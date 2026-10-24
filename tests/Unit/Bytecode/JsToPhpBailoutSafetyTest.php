<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsToPhpBailoutSafetyTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function cases(): iterable
    {
        yield 'boxed-free-return' => [
            'var n=0,value=\'x\'; function touch(){n++} function checked(){touch();return value'
                . '} var a=checked(),b=n,c=checked(); JSON.stringify([a,b,c,n]);',
            '["x",1,"x",2]',
            false,
            true,
        ];
        yield 'arithmetic-free-return' => [
            'var n=0,value=\'x\'; function touch(){n++} function checked(){touch();return value'
                . '+0} var a=checked(),b=n,c=checked(); JSON.stringify([a,b,c,n]);',
            '["x0",1,"x0",2]',
            false,
            true,
        ];
        yield 'boxed-call-return' => [
            'var n=0;function produce(){n++;return \'x\'}function checked(){return produce()}va'
                . 'r a=checked(),b=n,c=checked();JSON.stringify([a,b,c,n]);',
            '["x",1,"x",2]',
            false,
            true,
        ];
        yield 'nonnumeric-call-result' => [
            'var n=0;function produce(){n++;return \'x\'}function checked(){return produce()+0}'
                . 'var a=checked(),b=n,c=checked();JSON.stringify([a,b,c,n]);',
            '["x0",1,"x0",2]',
            false,
            true,
        ];
        yield 'member-after-effect' => [
            'var n=0;Object.prototype.change=function(){n++;delete this.value};Object.defineP'
                . 'roperty(Object.prototype,\'value\',{get(){n++;return \'x\'}});function checked(){con'
                . 'st object={value:0};object.change();return object.value}var a=checked(),b=n,c=ch'
                . 'ecked();JSON.stringify([a,b,c,n]);',
            '["x",2,"x",4]',
            false,
            true,
        ];
        yield 'branch-merge' => [
            'var n=0,value=\'x\';function touch(){n++}function checked(x){if(x>0)touch();return'
                . ' value+0}var a=checked(1),b=n,c=checked(0);JSON.stringify([a,b,c,n]);',
            '["x0",1,"x0",1]',
            false,
            true,
        ];
        yield 'conditional-pending-statements' => [
            'var n=0,value=\'x\';function produce(){n++;return \'x\'}function checked(x){return x'
                . '>0?produce()+0:value+0}var a=checked(1),b=n,c=checked(0);JSON.stringify([a,b,c,n'
                . ']);',
            '["x0",1,"x0",1]',
            false,
            true,
        ];
        yield 'while-backedge' => [
            'let limit=2;var n=0;function touch(){n++;limit=\'x\'}function checked(){let i=0;wh'
                . 'ile(i<limit){touch();i++}return i}JSON.stringify([checked(),n]);',
            '[1,1]',
            false,
            true,
        ];
        yield 'for-backedge' => [
            'let limit=2;var n=0;function touch(){n++;limit=\'x\'}function checked(){let i=0;fo'
                . 'r(;i<limit;i++){touch()}return i}JSON.stringify([checked(),n]);',
            '[1,1]',
            false,
            true,
        ];
        yield 'do-while-backedge' => [
            'let limit=2;var n=0;function touch(){n++;limit=\'x\'}function checked(){let i=0;do'
                . '{touch();i++}while(i<limit)return i}JSON.stringify([checked(),n]);',
            '[1,1]',
            false,
            true,
        ];
        yield 'callee-binding-write' => [
            'var n=0;function second(){n++;return \'x\'}function first(){n++;target=second;retu'
                . 'rn 0}var target=first;function checked(){target(0);return target(0)}JSON.stringi'
                . 'fy([checked(),n]);',
            '["x",2]',
            false,
            true,
        ];
        yield 'noncallable-no-coercion' => [
            'var n=0;var target={[Symbol.toPrimitive](){n++;return \'object\'}};function checke'
                . 'd(x){return target(x)}var error;try{checked(1)}catch(e){error=e instanceof TypeE'
                . 'rror}JSON.stringify([error,n]);',
            '[true,0]',
            false,
            true,
        ];
        yield 'global-getter-before-fallback' => [
            'var n=0;Object.defineProperty(globalThis,\'input\',{get(){n++;return \'x\'}});functi'
                . 'on checked(){return input+0}var a=checked(),b=n,c=checked();JSON.stringify([a,b,'
                . 'c,n]);',
            '["x0",1,"x0",2]',
            true,
            true,
        ];
        yield 'missing-raw-argument' => [
            'function identity(x){return x}identity(1);function checked(){let value=1;value=i'
                . 'dentity();return value}JSON.stringify([typeof checked(),typeof checked()]);',
            '["undefined","undefined"]',
            false,
            true,
        ];
        yield 'local-callee-fallback-and-arguments' => [
            'var events=[],lookups=0;function event(value){events.push(value)}function target'
                . '(value,required){event(value);return value}target(1,1);Object.defineProperty(Obj'
                . 'ect.prototype,\'method\',{get(){lookups++;return target}});function checked(value)'
                . '{const object={};object.method(value+=1);return value}var a=checked(7),b=checked'
                . '(8);JSON.stringify([a,b,lookups,events]);',
            '[8,9,2,[1,8,9]]',
            false,
            true,
        ];
        yield 'argument-source-order' => [
            'function identity(n){return n}function combine(a,b){return a*100+b}identity(1);c'
                . 'ombine(1,1);function checked(n){return combine((n=n+1),identity(n)+0)}JSON.strin'
                . 'gify([checked(1),checked(2)]);',
            '[202,303]',
            false,
            true,
        ];
        yield 'postfix-arguments' => [
            'function combine(a,b){return a*100+b}combine(1,1);function checked(n){return com'
                . 'bine(n++,n)}JSON.stringify([checked(1),checked(2)]);',
            '[102,203]',
            false,
            true,
        ];
        yield 'postfix-subtraction-left' => [
            'function identity(n){return n}identity(1);function checked(n){return n--*100-ide'
                . 'ntity(n)}JSON.stringify([checked(1),checked(3)]);',
            '[100,298]',
            false,
            true,
        ];
        yield 'own-object-write' => [
            'var hits=0;Object.defineProperty(Object.prototype,\'x\',{set(v){hits++}});function'
                . ' checked(n){const object={x:n};object.x=n+1;return object.x}JSON.stringify([chec'
                . 'ked(1),checked(2),hits]);',
            '[2,3,0]',
            true,
            true,
        ];
        yield 'new-object-key-setter' => [
            'var hits=0,value=\'x\';Object.defineProperty(Object.prototype,\'newKey\',{set(v){hit'
                . 's++}});function checked(n){const object={};object.newKey=n;return value+0}var a='
                . 'checked(1),b=hits,c=checked(2);JSON.stringify([a,b,c,hits]);',
            '["x0",1,"x0",2]',
            true,
            true,
        ];
        yield 'array-write-fractional' => [
            'var hits=0,value=\'x\';Object.defineProperty(Array.prototype,\'0.5\',{set(v){hits++}'
                . '});function checked(n){const array=[];array[n]=1;return value+0}var a=checked(0.'
                . '5),b=hits,c=checked(0.5);JSON.stringify([a,b,c,hits]);',
            '["x0",1,"x0",2]',
            true,
            true,
        ];
        yield 'array-write-nan' => [
            'var hits=0,value=\'x\';Object.defineProperty(Array.prototype,\'NaN\',{set(v){hits++}'
                . '});function checked(n){const array=[];array[n]=1;return value+0}var a=checked(Na'
                . 'N),b=hits,c=checked(NaN);JSON.stringify([a,b,c,hits]);',
            '["x0",1,"x0",2]',
            true,
            true,
        ];
        yield 'array-write-missing-integer' => [
            'var hits=0,value=\'x\';Object.defineProperty(Array.prototype,\'0\',{set(v){hits++}})'
                . ';function checked(n){const array=[];array[n]=1;return value+0}var a=checked(0),b'
                . '=hits,c=checked(0);JSON.stringify([a,b,c,hits]);',
            '["x0",1,"x0",2]',
            true,
            true,
        ];
        yield 'array-read-fractional-getter' => [
            'var hits=0;Object.defineProperty(Array.prototype,\'0.5\',{get(){hits++;return 4}})'
                . ';function checked(n){const array=[];return array[n]+0}var a=checked(0.5),b=hits,'
                . 'c=checked(0.5);JSON.stringify([a,b,c,hits]);',
            '[4,1,4,2]',
            true,
            true,
        ];
        yield 'unused-literal-initializer' => [
            'var events=0;function touch(){events++;return 7}function checked(n){const {a}={u'
                . 'nused:touch(),a:n};return a}var a=checked(1),b=events,c=checked(2);JSON.stringif'
                . 'y([a,b,c,events]);',
            '[1,1,2,2]',
            false,
            true,
        ];
        yield 'overwritten-literal-initializer' => [
            'var events=0;function touch(){events++;return 7}function checked(n){const {a}={a'
                . ':touch(),a:n};return a}var a=checked(1),b=events,c=checked(2);JSON.stringify([a,'
                . 'b,c,events]);',
            '[1,1,2,2]',
            false,
            true,
        ];
        yield 'object-literal-property-order' => [
            'Object.prototype.inspect=function(){return Object.keys(this).join(\',\')};function'
                . ' checked(n){const object={first:n,second:n};return object.inspect()}JSON.stringi'
                . 'fy([checked(1),checked(2)]);',
            '["first,second","first,second"]',
            false,
            true,
        ];
        yield 'escaped-object-mutation' => [
            'var hits=0,value=\'x\';Object.prototype.escape=function(){Object.defineProperty(th'
                . 'is,\'x\',{set(v){hits++},configurable:true})};function checked(n){const object={x:'
                . '0};object.escape();object.x=n;return value+0}var a=checked(1),b=hits,c=checked(2'
                . ');JSON.stringify([a,b,c,hits]);',
            '["x0",1,"x0",2]',
            false,
            true,
        ];
        yield 'late-local-loop-function' => [
            'function checked(){let count=0;while(condition(count)){count++}function conditio'
                . 'n(value){return value<1}return count}JSON.stringify([checked(),checked()]);',
            '[1,1]',
            false,
            true,
        ];
        yield 'conditional-local-loop-function' => [
            'function checked(n){if(n>0){function condition(value){return value<1}}let count='
                . '0;while(condition(count)){count++}return count}JSON.stringify([checked(1),checke'
                . 'd(1)]);',
            '[1,1]',
            false,
            false,
        ];
        yield 'recursive-local-function' => [
            'function checked(){function helper(n){return n>0?helper(n-1):5}return helper(3)}'
                . 'JSON.stringify([checked(),checked()]);',
            '[5,5]',
            false,
            true,
        ];
    }

    #[DataProvider('cases')]
    public function testSpeculativeFallbackDoesNotReplayEffects(string $source, string $expected, bool $php, bool $vm): void
    {
        $engine = new Engine();
        self::assertSame($expected, $engine->eval($source));
        $function = $engine->getGlobalEnv()->get('checked');
        self::assertInstanceOf(JsFunction::class, $function);
        if ($php) {
            self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        } else {
            self::assertNull($function->phpCompiled);
            self::assertSame($vm, $function->compiled !== null);
        }
    }
}
