<?php

namespace Tests\Unit;

use App\Support\Html\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_scripts_handlers_and_dangerous_urls_are_removed(): void
    {
        $dirty = '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)" onmouseover="y()">link</a>'
            .'<img src="x" onerror="z()"><iframe src="//evil"></iframe><b style="color:red">bold</b></p>';

        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringContainsString('<b>bold</b>', $clean);
    }

    public function test_safe_markup_arabic_text_and_external_links_are_preserved(): void
    {
        $clean = HtmlSanitizer::clean('<h2>مرحبا بكم</h2><p dir="rtl">نص <a href="https://example.com" target="_blank">رابط</a></p>');

        $this->assertStringContainsString('<h2>مرحبا بكم</h2>', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringContainsString('dir="rtl"', $clean);
    }

    public function test_null_and_empty_pass_through(): void
    {
        $this->assertNull(HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean(''));
    }
}
