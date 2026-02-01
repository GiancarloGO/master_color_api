<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SmtpConnectionTest extends TestCase
{
    /**
     * Test SMTP connection configuration
     */
    public function test_smtp_configuration_is_loaded(): void
    {
        // In test environment, mail.default might be 'array', so we check the smtp config directly
        $this->assertEquals('smtp.gmail.com', Config::get('mail.mailers.smtp.host'));
        $this->assertEquals(587, Config::get('mail.mailers.smtp.port'));
        $this->assertEquals('mastercoloreirl@gmail.com', Config::get('mail.mailers.smtp.username'));
        $this->assertEquals('mastercoloreirl@gmail.com', Config::get('mail.from.address'));
        $this->assertEquals('Master Color', Config::get('mail.from.name'));
        $this->assertEquals(30, Config::get('mail.mailers.smtp.timeout'));
    }

    /**
     * Test SMTP timeout configuration
     */
    public function test_smtp_timeout_is_configured(): void
    {
        $timeout = Config::get('mail.mailers.smtp.timeout');
        
        $this->assertNotNull($timeout, 'SMTP timeout should not be null');
        $this->assertEquals(30, $timeout, 'SMTP timeout should be 30 seconds');
        $this->assertTrue(is_numeric($timeout), 'SMTP timeout should be numeric');
    }
}
