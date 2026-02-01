<?php

namespace Tests\Feature;

use App\Mail\ClientEmailVerification;
use App\Mail\ClientResetPassword;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that password reset emails are queued
     */
    public function test_forgot_password_queues_email(): void
    {
        Mail::fake();
        Queue::fake();

        // Create a test client
        $client = Client::factory()->create([
            'email' => 'test@example.com',
            'email_verified_at' => now(),
        ]);

        // Request password reset
        $response = $this->postJson('/api/client/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200);

        // Assert email was queued (not sent immediately)
        Mail::assertQueued(ClientResetPassword::class, function ($mail) {
            return $mail->email === 'test@example.com';
        });

        // Assert email was NOT sent synchronously
        Mail::assertNotSent(ClientResetPassword::class);
    }

    /**
     * Test that verification emails are queued during registration
     */
    public function test_registration_queues_verification_email(): void
    {
        Mail::fake();

        // Register a new client
        $response = $this->postJson('/api/client/auth/register', [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'client_type' => 'natural',
            'identity_document' => '12345678',
            'document_type' => 'DNI',
            'phone' => '987654321',
            'address_full' => 'Test Address 123',
            'district' => 'Test District',
            'province' => 'Test Province',
            'department' => 'Test Department',
            'reference' => 'Near test location',
        ]);

        $response->assertStatus(201);

        // Assert verification email was queued
        Mail::assertQueued(ClientEmailVerification::class, function ($mail) {
            return $mail->client->email === 'newuser@example.com';
        });
    }

    /**
     * Test that resend verification emails are queued
     */
    public function test_resend_verification_queues_email(): void
    {
        Mail::fake();

        // Create unverified client
        $client = Client::factory()->create([
            'email' => 'unverified@example.com',
            'email_verified_at' => null,
        ]);

        // Resend verification email
        $response = $this->postJson('/api/client/auth/resend-verification', [
            'email' => 'unverified@example.com',
        ]);

        $response->assertStatus(200);

        // Assert email was queued
        Mail::assertQueued(ClientEmailVerification::class, function ($mail) {
            return $mail->client->email === 'unverified@example.com';
        });
    }

    /**
     * Test that queued emails have retry configuration
     */
    public function test_queued_emails_have_retry_configuration(): void
    {
        $resetPasswordMail = new ClientResetPassword('test-token', 'test@example.com', 'Test User');
        $verificationMail = new ClientEmailVerification((object)['name' => 'Test', 'verification_token' => 'token']);

        // Assert retry configuration
        $this->assertEquals(3, $resetPasswordMail->tries);
        $this->assertEquals(60, $resetPasswordMail->timeout);
        
        $this->assertEquals(3, $verificationMail->tries);
        $this->assertEquals(60, $verificationMail->timeout);
    }
}
