<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\Pipeline\HooshaService;

class PerfNotesTest extends TestCase
{
    public function testPerfNotesPresent(): void
    {
        $svc = new HooshaService(null);
        $res = $svc->process("نامت چیه\nمیای شرکت یا نه", []);
        $notes = $res['notes'] ?? [];
        $hasTokens=false;
        foreach ($notes as $n){ if (str_starts_with($n,'perf:tokens(')) $hasTokens=true; }
        $this->assertTrue($hasTokens,'Missing perf:tokens note');
    }
}
