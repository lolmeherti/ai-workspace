<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Search\PromptInjectionFilter;

final class PromptInjectionEval
{
    private const FIXTURE_PATH = __DIR__ . '/fixtures/prompt-injection.json';

    public function run(): int
    {
        $raw = file_get_contents(self::FIXTURE_PATH);
        if ($raw === false) {
            fwrite(STDERR, 'Cannot read fixtures: ' . self::FIXTURE_PATH . "\n");
            return 2;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['slices'])) {
            fwrite(STDERR, "Fixture file missing 'slices' key.\n");
            return 2;
        }

        $slices = $data['slices'];

        echo "=== PromptInjectionFilter fixture eval ===\n\n";

        $this->reportExplicit($slices['A_explicit']['cases'] ?? []);
        $this->reportFalsePositives('Slice B — benign evidence (false-positive rate, lower is better)', $slices['B_benign']['cases'] ?? []);
        $this->reportFalsePositives('Slice C — meta-discussion of injection (false-positive rate, lower is better)', $slices['C_meta']['cases'] ?? []);
        $this->reportSlipThrough('Slice D — paraphrased attacks (slip-through, lower is better)', $slices['D_paraphrase']['cases'] ?? []);
        $this->reportSlipThrough('Slice E — obfuscated attacks (slip-through, lower is better)', $slices['E_obfuscated']['cases'] ?? []);
        $this->reportPreservation($slices['F_mixed']['cases'] ?? []);

        return 0;
    }

    private function passes(array $case, string $out): bool
    {
        $expect = $case['expect'];
        if ($expect === 'empty') {
            return trim($out) === '';
        }
        if ($expect === 'unchanged') {
            return $out === $case['input'];
        }
        if (is_array($expect) && isset($expect['keep'], $expect['drop'])) {
            foreach ($expect['keep'] as $keep) {
                if (!str_contains($out, $keep)) {
                    return false;
                }
            }
            foreach ($expect['drop'] as $drop) {
                if (str_contains($out, $drop)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private function truncate(string $s): string
    {
        $s = str_replace(["\n", "\r", "\t"], ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if (mb_strlen($s) > 90) {
            return mb_substr($s, 0, 87) . '...';
        }
        return $s;
    }

    private function reportExplicit(array $cases): void
    {
        $byFamily = [];
        $slips = [];
        foreach ($cases as $case) {
            $out = PromptInjectionFilter::sanitize($case['input']);
            $ok = $this->passes($case, $out);
            $family = $case['family'];
            $byFamily[$family]['ok'] = ($byFamily[$family]['ok'] ?? 0) + ($ok ? 1 : 0);
            $byFamily[$family]['total'] = ($byFamily[$family]['total'] ?? 0) + 1;
            if (!$ok) {
                $slips[] = ['input' => $case['input'], 'out' => $out, 'family' => $family];
            }
        }

        $total = 0;
        $okTotal = 0;
        echo "Slice A — explicit injection (recall, higher is better)\n";
        foreach ($byFamily as $family => $counts) {
            printf("  %-24s %d/%d\n", $family, $counts['ok'], $counts['total']);
            $total += $counts['total'];
            $okTotal += $counts['ok'];
        }
        printf("  %-24s %d/%d  (%.1f%%)\n", 'OVERALL', $okTotal, $total, $total ? 100 * $okTotal / $total : 0);
        $this->printSlips('A', $slips);
    }

    private function reportFalsePositives(string $label, array $cases): void
    {
        $changed = 0;
        $slips = [];
        foreach ($cases as $case) {
            $out = PromptInjectionFilter::sanitize($case['input']);
            if ($out !== $case['input']) {
                $changed++;
                $slips[] = ['input' => $case['input'], 'out' => $out, 'family' => ''];
            }
        }
        $total = count($cases);
        printf("%s\n", $label);
        printf("  changed %d/%d  (%.1f%%)\n", $changed, $total, $total ? 100 * $changed / $total : 0);
        $this->printSlips('FP', $slips);
    }

    private function reportSlipThrough(string $label, array $cases): void
    {
        $slipped = 0;
        $slips = [];
        foreach ($cases as $case) {
            $out = PromptInjectionFilter::sanitize($case['input']);
            if (!$this->passes($case, $out)) {
                $slipped++;
                $slips[] = ['input' => $case['input'], 'out' => $out, 'family' => $case['family'] ?? ''];
            }
        }
        $total = count($cases);
        printf("%s\n", $label);
        printf("  slipped %d/%d  (%.1f%%)\n", $slipped, $total, $total ? 100 * $slipped / $total : 0);
        $this->printSlips('SLIP', $slips);
    }

    private function reportPreservation(array $cases): void
    {
        $preserved = 0;
        $slips = [];
        foreach ($cases as $case) {
            $out = PromptInjectionFilter::sanitize($case['input']);
            if ($this->passes($case, $out)) {
                $preserved++;
            } else {
                $slips[] = ['input' => $case['input'], 'out' => $out, 'family' => ''];
            }
        }
        $total = count($cases);
        echo "Slice F — mixed fact+attack (preservation, higher is better)\n";
        printf("  preserved %d/%d  (%.1f%%)\n", $preserved, $total, $total ? 100 * $preserved / $total : 0);
        $this->printSlips('F', $slips);
    }

    private function printSlips(string $tag, array $slips): void
    {
        if ($slips === []) {
            echo "  (none)\n\n";
            return;
        }
        foreach ($slips as $s) {
            $label = $s['family'] !== '' ? $tag . '/' . $s['family'] : $tag;
            echo "  [" . $label . "] " . $this->truncate($s['input']) . "\n";
            echo "      -> " . $this->truncate($s['out']) . "\n";
        }
        echo "\n";
    }
}

exit((new PromptInjectionEval())->run());
