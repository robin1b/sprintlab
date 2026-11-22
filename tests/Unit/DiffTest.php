<?php

namespace Tests\Unit;

use App\Services\GitHub\Diff;
use PHPUnit\Framework\TestCase;

class DiffTest extends TestCase
{
    private string $patch = "@@ -10,4 +10,5 @@ class LeaseRequest\n public function approve(): void\n {\n-    \$this->status = 'approved';\n+    if (\$this->status === 'approved') {\n+        return;\n     }\n\\ No newline at end of file";

    public function test_it_finds_the_lines_a_comment_may_point_at(): void
    {
        // Lines 10 and 11 are context, 12 and 13 were added, 14 is context.
        // The removed line has no number in the new file.
        $this->assertSame([10, 11, 12, 13, 14], Diff::commentableLines($this->patch));
    }

    public function test_it_handles_several_hunks(): void
    {
        $patch = "@@ -1,2 +1,2 @@\n-old\n+new\n same\n@@ -40,2 +40,3 @@\n keep\n+added\n keep";

        $this->assertSame([1, 2, 40, 41, 42], Diff::commentableLines($patch));
    }

    public function test_it_numbers_the_lines_of_the_new_file(): void
    {
        $numbered = Diff::numbered($this->patch);

        $this->assertStringContainsString('@@ -10,4 +10,5 @@ class LeaseRequest', $numbered);
        $this->assertStringContainsString("+    12 |     if (\$this->status === 'approved') {", $numbered);
        $this->assertStringContainsString("-       |     \$this->status = 'approved';", $numbered);
        $this->assertStringNotContainsString('No newline', $numbered);
    }

    public function test_an_empty_patch_has_no_lines(): void
    {
        $this->assertSame([], Diff::commentableLines(''));
    }
}
