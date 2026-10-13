<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Value\JsElement;
use Phasis\Value\JsSymbol;

/**
 * React's element and component type symbols, registered with Symbol.for
 * under React's own keys so libraries that compare `$$typeof` keep working.
 */
final class ReactSymbols
{
    public static JsSymbol $element;
    public static JsSymbol $portal;
    public static JsSymbol $fragment;
    public static JsSymbol $strictMode;
    public static JsSymbol $profiler;
    public static JsSymbol $consumer;
    public static JsSymbol $context;
    public static JsSymbol $forwardRef;
    public static JsSymbol $suspense;
    public static JsSymbol $suspenseList;
    public static JsSymbol $memo;
    public static JsSymbol $lazy;
    public static JsSymbol $scope;
    public static JsSymbol $activity;
    public static JsSymbol $legacyHidden;
    public static JsSymbol $memoCacheSentinel;
    public static JsSymbol $viewTransition;
    public static JsSymbol $recoverable;
    public static JsSymbol $optimisticKey;
    public static JsSymbol $clientReference;

    private static bool $ready = false;

    public static function init(): void
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        self::$element = JsElement::elementSymbol();
        self::$portal = SymbolConstructor::registered('react.portal');
        self::$fragment = SymbolConstructor::registered('react.fragment');
        self::$strictMode = SymbolConstructor::registered('react.strict_mode');
        self::$profiler = SymbolConstructor::registered('react.profiler');
        self::$consumer = SymbolConstructor::registered('react.consumer');
        self::$context = SymbolConstructor::registered('react.context');
        self::$forwardRef = SymbolConstructor::registered('react.forward_ref');
        self::$suspense = SymbolConstructor::registered('react.suspense');
        self::$suspenseList = SymbolConstructor::registered('react.suspense_list');
        self::$memo = SymbolConstructor::registered('react.memo');
        self::$lazy = SymbolConstructor::registered('react.lazy');
        self::$scope = SymbolConstructor::registered('react.scope');
        self::$activity = SymbolConstructor::registered('react.activity');
        self::$legacyHidden = SymbolConstructor::registered('react.legacy_hidden');
        self::$memoCacheSentinel = SymbolConstructor::registered('react.memo_cache_sentinel');
        self::$viewTransition = SymbolConstructor::registered('react.view_transition');
        self::$recoverable = SymbolConstructor::registered('react.recoverable');
        self::$optimisticKey = SymbolConstructor::registered('react.optimistic_key');
        self::$clientReference = SymbolConstructor::registered('react.client.reference');
    }
}
