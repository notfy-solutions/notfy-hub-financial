<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\ClassMethodAverageCyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\MethodCyclomaticComplexityIsHigh;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Commenting\TodoSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\WhiteSpace\ScopeClosingBraceSniff;
use PHP_CodeSniffer\Standards\PSR12\Sniffs\Classes\ClassInstantiationSniff;
use PhpCsFixer\Fixer\Basic\BracesFixer;
use PhpCsFixer\Fixer\ClassNotation\ClassDefinitionFixer;
use PhpCsFixer\Fixer\Operator\NewWithBracesFixer;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousInterfaceNamingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\DocCommentSpacingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\InlineDocCommentDeclarationSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\DisallowEmptySniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;

return [

    'preset' => 'laravel',

    'ide' => null,

    'exclude' => [],

    'add' => [],

    'remove' => [
        // "Exception" and "Interface" suffixes are conventional PHP naming,
        // not a smell — renaming them would break the public API this
        // package exposes to consumers.
        SuperfluousExceptionNamingSniff::class,
        SuperfluousInterfaceNamingSniff::class,

        // Interface contracts (PostalCodeProviderInterface) require every
        // implementation to accept the same signature even when a given
        // provider does not need every parameter (e.g. a Brazil-only
        // provider still receives $country).
        UnusedParameterSniff::class,

        // JSON payloads decoded from third-party APIs are honestly typed as
        // array<string, mixed> — PHPStan level 9 requires this exact shape.
        // This sniff flags the literal word "mixed" anywhere in a docblock
        // generic, including as a value-type, which fights the typing this
        // codebase's PHPStan baseline already enforces.
        DisallowMixedTypeHintSniff::class,

        // empty() on scalars from application config is a clear, idiomatic
        // guard here — not a real ambiguity risk.
        DisallowEmptySniff::class,

        // Pint's `single_line_empty_body` fixer collapses every empty body
        // (promoted-constructor classes, empty exception subclasses) to a
        // one-line `{}`; these two fixers want the opposite (multi-line)
        // and only expose a config knob for anonymous classes. Pint is this
        // repo's authoritative formatter.
        BracesFixer::class,
        ClassDefinitionFixer::class,

        // Same Pint-vs-PHPCS conflict for the `{}` collapsed empty-body
        // style: this sniff wants the closing brace split onto its own
        // line for promoted-constructor classes; Pint disagrees and wins.
        ScopeClosingBraceSniff::class,

        // Pint's active ruleset strips parentheses from zero-arg `new
        // ClassName;` instantiations; these two fixers demand the opposite.
        // Pint is this repo's authoritative formatter and always wins.
        ClassInstantiationSniff::class,
        NewWithBracesFixer::class,

        // Pint enforces its own (asymmetric) blank-line rule between
        // docblock annotations; this sniff's single scalar setting can't
        // express it, so the two fixers fight on every multi-tag docblock.
        DocCommentSpacingSniff::class,

        // Nameless `/** @var Type */` directly above a `return EXPR;` is
        // the correct, PHPStan-recognised way to narrow a third-party
        // mixed return (config(), json()) without introducing a throwaway
        // variable — this sniff only accepts the assignment form.
        InlineDocCommentDeclarationSniff::class,

        // False positive in a pt-BR codebase: the sniff matches the English
        // word "TODO" case-insensitively, which collides with the common
        // Portuguese word "todo" ("every"/"all") inside prose comments.
        TodoSniff::class,
    ],

    'config' => [
        // Small library: keep this far stricter than a full app, but allow
        // real, single-responsibility methods (API response parsing, DI
        // wiring) that naturally run a little past an aggressive 20-line
        // default without gaining anything from being artificially split.
        FunctionLengthSniff::class => [
            'maxLinesLength' => 45,
        ],

        // 80 columns is a legacy terminal-width default; 120 is the
        // practical modern standard and still catches genuinely long lines.
        LineLengthSniff::class => [
            'lineLimit' => 120,
            'absoluteLineLimit' => 140,
        ],

        // Gateway integrations (building/parsing third-party payment
        // payloads) inherently carry more branches than a generic default
        // of 5 assumes. Calibrated to the current worst case (21/8) plus
        // headroom — still an order of magnitude stricter than treating
        // this as unlimited.
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 25,
        ],
        MethodCyclomaticComplexityIsHigh::class => [
            'maxMethodComplexity' => 10,
        ],

        // StripeWebhookParser::isValid() validates a security-critical HMAC
        // signature (missing header, missing secret, malformed timestamp,
        // malformed signature, replay-window expiry, signature mismatch
        // across possibly-rotated secrets) — each branch is a real,
        // independent security gate, not incidental complexity.
        ClassMethodAverageCyclomaticComplexityIsHigh::class => [
            'maxClassMethodAverageComplexity' => 6,
        ],
    ],

    'requirements' => [
        'min-quality' => 99,
        'min-complexity' => 99,
        'min-architecture' => 99,
        'min-style' => 99,
        'disable-security-check' => false,
    ],

    'threads' => null,

    'timeout' => 60,
];
