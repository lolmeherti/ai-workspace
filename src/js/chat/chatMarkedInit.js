/**
 * @file js/chat/chatMarkedInit.js
 * @description Configure marked with KaTeX math rendering and guard against
 *              prose dollar signs (currency) being misparsed as math.
 */

const LIT_DOLLAR = 'LSY_DOLLAR_SENTINEL';

/**
 * Protect prose "$" (currency amounts) from marked-katex's nonStandard math
 * tokenizer while still letting real math render. Models emit both: dollar
 * figures in prose ("$850 billion", "$6.5 billion") and genuine formulas
 * ("$x = \\frac{1}{2}$"). With nonStandard math enabled every "$...$" pair is
 * treated as inline math, so currency figures pair up across a paragraph and
 * the text between them is rendered as a broken vertical math expression
 * (asterisks become ∗, spaces collapse).
 *
 * Real math is kept and rendered by KaTeX:
 *   - $$...$$ and \[...\] (may span lines)
 *   - \(...\)
 *   - tight inline math with no whitespace ($x^2$, $\frac{a}{b}$)
 *   - spaced inline math that carries a strong math signal and a letter: a
 *     LaTeX command (\frac, \sum, \int, ...) or = ^ _ { }, plus at least one
 *     letter (so a price comparison like "$5 = $6" stays literal)
 *
 * A spaced "$...$" with no such signal is prose/currency ("$10–15 billion",
 * "$20 average") and its "$" is replaced by a sentinel that the postprocess
 * hook turns back into a literal "$" after marked produces HTML.
 */
function guardMarkdownDollars(text) {
    const math = [];
    const stashMath = (s) => { math.push(s); return 'LSY_MATH_SENTINEL_' + (math.length - 1) + '_'; };
    const isBareNumber = (s) => /^[\d.,\s€£¥%\-–—]+$/.test(s.trim());

    // 1. Display math and bracket math: keep verbatim.
    text = text.replace(/\$\$[\s\S]+?\$\$/g, stashMath);
    text = text.replace(/\\\[[\s\S]+?\\\]/g, stashMath);
    text = text.replace(/\\\([^\n]*?\\\)/g, stashMath);

    // 2. Tight inline math first (no whitespace inside) so a currency "$20"
    //    can never steal the opening "$" of a following "$x^2$".
    text = text.replace(/\$([^$\s]+)\$/g, (match, inner) =>
        (!isBareNumber(inner) && inner.trim() !== '')
            ? stashMath(match)
            : LIT_DOLLAR + inner + LIT_DOLLAR
    );

    // 3. Spaced inline math: keep only when it has a strong math signal
    //    (LaTeX command, or = ^ _ { }) AND a letter. This keeps
    //    "$x = \frac{1}{2}$" and "$x^2 + y^2 = z^2$" while rejecting a price
    //    comparison like "$5 = $6" (digits only, no letters).
    text = text.replace(/\$([^$\n]+?)\$/g, (match, inner) => {
        const strong = /\\[a-zA-Z]+/.test(inner) || /[=^_{}]/.test(inner);
        const hasLetter = /\p{L}/u.test(inner);
        return (strong && hasLetter) ? stashMath(match) : LIT_DOLLAR + inner + LIT_DOLLAR;
    });

    // 4. Any remaining lone "$" is currency/prose.
    text = text.replace(/\$/g, LIT_DOLLAR);

    // 5. Restore real math before parsing so KaTeX renders it.
    return text.replace(/LSY_MATH_SENTINEL_(\d+)_/g, (_, i) => math[+i]);
}

export function initChatMarked() {
    if (typeof marked !== 'undefined' && typeof markedKatex !== 'undefined') {
        marked.use(markedKatex({
            throwOnError: false,
            nonStandard: true,
            strict: 'ignore'
        }));
    }

    if (typeof marked !== 'undefined') {
        // Single newlines become line breaks (matches jobs rendering and keeps
        // model output with short lines from collapsing into one paragraph).
        marked.setOptions({ breaks: true });

        // Guard currency/prose "$" so it is never parsed as math. Uses marked's
        // hooks (preprocess before tokenizing, postprocess on the HTML) rather than
        // overriding marked.parse: marked v15 exposes `parse` as a getter-only
        // property, so assigning to it throws in strict mode (ES modules).
        marked.use({
            hooks: {
                preprocess: (markdown) => guardMarkdownDollars(markdown),
                postprocess: (html) => html.replace(new RegExp(LIT_DOLLAR, 'g'), () => '$')
            }
        });
    }
}

initChatMarked();
