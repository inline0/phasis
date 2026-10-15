<?php

declare(strict_types=1);

namespace Phasis\Parser\Jsx;

use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Jsx\JsxAttribute;
use Phasis\Ast\Jsx\JsxElement;
use Phasis\Ast\Jsx\JsxEmptyExpression;
use Phasis\Ast\Jsx\JsxExpressionContainer;
use Phasis\Ast\Jsx\JsxFragment;
use Phasis\Ast\Jsx\JsxIdentifier;
use Phasis\Ast\Jsx\JsxNamespacedName;
use Phasis\Ast\Jsx\JsxSpreadChild;
use Phasis\Ast\Jsx\JsxText as JsxTextNode;
use Phasis\Ast\Node;
use Phasis\BuiltIn\Jsx\Html\HtmlRenderer;
use Phasis\BuiltIn\Jsx\React\Fizz\Dom;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsString;

/**
 * Precompile mode: the static HTML inside an element's children becomes
 * string parts at parse time, with holes for the dynamic children and
 * attributes, the way Deno's `jsx: "precompile"` works.
 *
 * Static markup is serialized with the target profile's own serializers
 * (React's pushAttribute and text escaping, or preact-render-to-string's),
 * so the result is byte-identical to rendering the elements. For the React
 * profile each part records whether it starts or ends with a text node,
 * so `<!-- -->` separators land where React puts them, and each hole
 * records its tree position (React's array forks and the enclosing tags),
 * so useId and format contexts are what React computes.
 */
final class JsxPrecompiler
{
    /**
     * Elements whose start tag React writes with pushStartGenericElement (or
     * as a generic void element) and whose children keep the default format
     * context. Everything else stays a real element.
     */
    private const TEMPLATE_TAGS = [
        'a' => true, 'abbr' => true, 'address' => true, 'article' => true, 'aside' => true, 'b' => true,
        'bdi' => true, 'bdo' => true, 'blockquote' => true, 'br' => true, 'cite' => true, 'code' => true,
        'data' => true, 'dd' => true, 'del' => true, 'details' => true, 'dfn' => true, 'div' => true,
        'dl' => true, 'dt' => true, 'em' => true, 'fieldset' => true, 'figcaption' => true, 'figure' => true,
        'footer' => true, 'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'header' => true, 'hgroup' => true, 'hr' => true, 'i' => true, 'ins' => true, 'kbd' => true,
        'label' => true, 'legend' => true, 'li' => true, 'main' => true, 'mark' => true, 'nav' => true,
        'ol' => true, 'p' => true, 'q' => true, 'rp' => true, 'rt' => true, 'ruby' => true, 's' => true,
        'samp' => true, 'search' => true, 'section' => true, 'small' => true, 'span' => true,
        'strong' => true, 'sub' => true, 'summary' => true, 'sup' => true, 'time' => true, 'u' => true,
        'ul' => true, 'var' => true, 'wbr' => true,
    ];

    private const VOID_TAGS = ['br' => true, 'hr' => true, 'wbr' => true];

    private const PART_STARTS_WITH_TEXT = 1;
    private const PART_ENDS_WITH_TEXT = 2;

    /** @var list<string> */
    private array $parts = [];
    private string $part = '';
    private int $partFlags = 0;
    /** @var list<int> */
    private array $flags = [];
    /** @var list<Node> Hole values, lowered. */
    private array $holes = [];
    /** @var list<?list<string|int>> Per hole: tree steps (tags and fork pairs), or null for an attribute hole. */
    private array $steps = [];
    /** @var list<?list<string>> Per hole: null for a child, or the attribute name (and the tag, for an `a` href). */
    private array $attributes = [];
    private bool $text = false;

    /**
     * @param \Closure(Node): Node $lowerElement Lowers a JSX element or fragment the normal way.
     */
    public function __construct(
        private readonly bool $react,
        private readonly \Closure $lowerElement,
    ) {
    }

    /** True when $element's children can become a template. */
    public static function canTemplateChildren(JsxElement $element, bool $react): bool
    {
        if (!self::isTemplatableElement($element, $react)) {
            return false;
        }
        foreach ($element->children as $child) {
            if ($child instanceof JsxSpreadChild) {
                return false;
            }
        }
        foreach ($element->children as $child) {
            if ($child instanceof JsxElement && self::isTemplatableElement($child, $react)) {
                return true;
            }
        }
        return false;
    }

    private static function tagName(JsxElement $element): ?string
    {
        $name = $element->openingElement->name;
        return $name instanceof JsxIdentifier ? $name->name : null;
    }

    private static function isTemplatableElement(JsxElement $element, bool $react): bool
    {
        $tag = self::tagName($element);
        if ($tag === null || !isset(self::TEMPLATE_TAGS[$tag])) {
            return false;
        }
        $names = [];
        foreach ($element->openingElement->attributes as $attribute) {
            if (!$attribute instanceof JsxAttribute) {
                return false;
            }
            $name = self::attributeName($attribute);
            if ($name === 'children' || $name === 'dangerouslySetInnerHTML') {
                return false;
            }
            $names[$name] = true;
        }
        if (!$react && ((isset($names['htmlFor']) && isset($names['for'])) || (isset($names['className']) && isset($names['class'])))) {
            return false;
        }
        if (isset(self::VOID_TAGS[$tag])) {
            foreach ($element->children as $child) {
                if (!$child instanceof JsxTextNode || JsxText::cleanChild($child->raw) !== null) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function attributeName(JsxAttribute $attribute): string
    {
        return $attribute->name instanceof JsxNamespacedName
            ? $attribute->name->namespace->name . ':' . $attribute->name->name->name
            : ($attribute->name instanceof JsxIdentifier ? $attribute->name->name : '');
    }

    /**
     * Compile $element's children. Returns the static parts plus a trailing
     * metadata entry, and the hole values in order.
     *
     * @return array{0: list<string|array<string, mixed>>, 1: list<Node>}
     */
    public function compileChildren(JsxElement $element): array
    {
        $this->emitList($element->children, []);
        $this->closePart();
        $meta = ['p' => $this->react ? 'react' : 'html', 'f' => $this->flags, 's' => $this->steps, 'a' => $this->attributes];
        return [[...$this->parts, $meta], $this->holes];
    }

    /**
     * React renders an element's children as one node when there is one,
     * and as an array (forking the tree id per index) when there are more.
     *
     * @param Node[] $children
     * @param list<string|int> $steps
     */
    private function emitList(array $children, array $steps): void
    {
        $items = [];
        foreach ($children as $child) {
            if ($child instanceof JsxTextNode) {
                $text = JsxText::cleanChild($child->raw);
                if ($text !== null) {
                    $items[] = $text;
                }
            } elseif ($child instanceof JsxExpressionContainer) {
                if (!$child->expression instanceof JsxEmptyExpression) {
                    $items[] = $child;
                }
            } elseif ($child instanceof JsxElement || $child instanceof JsxFragment) {
                $items[] = $child;
            }
        }
        $count = count($items);
        foreach ($items as $index => $item) {
            $itemSteps = $count > 1 ? [...$steps, $count, $index] : $steps;
            if (is_string($item)) {
                $this->emitText($item, $count > 1);
            } elseif ($item instanceof JsxElement && self::isTemplatableElement($item, $this->react)) {
                $this->emitElement($item, $itemSteps);
            } elseif ($item instanceof JsxExpressionContainer) {
                $this->emitHole($item->expression, $itemSteps);
            } else {
                $this->emitHole(($this->lowerElement)($item), $itemSteps);
            }
        }
    }

    private function emitText(string $text, bool $inArray): void
    {
        if ($this->react) {
            if ($inArray) {
                // pushTextInstance: a text node of its own.
                if ($this->part === '') {
                    $this->partFlags |= self::PART_STARTS_WITH_TEXT;
                }
                $this->part .= Dom::escapeText($text);
                $this->text = true;
                return;
            }
            // A lone string child: React writes it inline after the start tag.
            $this->part .= Dom::escapeText($text);
            $this->text = false;
            return;
        }
        $this->part .= HtmlRenderer::encodeEntities($text);
    }

    /** @param list<string|int> $steps */
    private function emitElement(JsxElement $element, array $steps): void
    {
        $tag = (string) self::tagName($element);
        $this->part .= '<' . $tag;
        $this->text = false;
        foreach ($element->openingElement->attributes as $attribute) {
            if ($attribute instanceof JsxAttribute) {
                $this->emitAttribute($tag, $attribute);
            }
        }
        if (isset(self::VOID_TAGS[$tag])) {
            $this->part .= '/>';
            $this->text = false;
            return;
        }
        $this->part .= '>';
        $this->emitList($element->children, [...$steps, $tag]);
        $this->part .= '</' . $tag . '>';
        $this->text = false;
    }

    private function emitAttribute(string $tag, JsxAttribute $attribute): void
    {
        $name = self::attributeName($attribute);
        if ($name === 'key') {
            return;
        }
        $value = $attribute->value;
        if ($value === null || ($value instanceof Literal && is_string($value->value))) {
            $static = $value === null ? JsBoolean::of(true) : new JsString((string) $value->value);
            // React throws for a string style at render time; keep that for runtime.
            if (!($this->react && $name === 'style')) {
                $this->part .= $this->staticAttribute($tag, $name, $static);
                return;
            }
        }
        if ($value instanceof JsxExpressionContainer) {
            $expression = $value->expression;
            if (
                strlen($name) > 2 && strtolower(substr($name, 0, 2)) === 'on'
                && ($expression instanceof ArrowFunction || $expression instanceof FunctionExpression)
            ) {
                // Event handlers never reach server HTML; skip creating the closure.
                return;
            }
            $holeValue = $expression;
        } elseif ($value instanceof JsxElement || $value instanceof JsxFragment) {
            $holeValue = ($this->lowerElement)($value);
        } else {
            $holeValue = $value;
        }
        $this->closePart();
        // The attribute's name is static, so it lives in the template's
        // metadata and the hole is just the value.
        $this->holes[] = $holeValue;
        $this->steps[] = null;
        $this->attributes[] = $tag === 'a' && $name === 'href' ? [$name, 'a'] : [$name];
    }

    private function staticAttribute(string $tag, string $name, JsBoolean|JsString $value): string
    {
        if ($this->react) {
            $chunks = [];
            if ($tag === 'a' && $name === 'href' && $value instanceof JsString && $value->value === '') {
                Dom::pushStringAttribute($chunks, 'href', '');
            } else {
                Dom::pushAttribute($chunks, $name, $value);
            }
            return implode('', $chunks);
        }
        return HtmlRenderer::attributeChunk($name, $value, null, false);
    }

    /** @param list<string|int> $steps */
    private function emitHole(Node $value, array $steps): void
    {
        $this->closePart();
        $this->holes[] = $value;
        $this->steps[] = $steps;
        $this->attributes[] = null;
    }

    private function closePart(): void
    {
        if ($this->text && $this->part !== '') {
            $this->partFlags |= self::PART_ENDS_WITH_TEXT;
        }
        $this->parts[] = $this->part;
        $this->flags[] = $this->partFlags;
        $this->part = '';
        $this->partFlags = 0;
        $this->text = false;
    }
}
