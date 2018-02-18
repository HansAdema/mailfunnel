<?php

namespace Test\Http\Controllers;

use App\Mail\InboundMail;
use App\Mail\OutboundMail;
use App\Models\Address;
use App\Models\Domain;
use App\Models\Message;
use App\Models\User;
use App\ReplyEmail;
use Faker\Provider\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendgridControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $inboundData;
    protected $outboundData;
    protected $authHeaders;

    protected $user;

    public function setUp()
    {
        parent::setUp();

        Mail::fake();

        $this->user = factory(User::class)->create();
        Domain::create([
            'domain' => 'example.com',
            'user_id' => $this->user->id,
        ]);

        $this->authHeaders = [
            'PHP_AUTH_USER' => 'TestUser',
            'PHP_AUTH_PW' => 'TestPassword'
        ];

        $this->inboundData = $this->outboundData = [
            'headers' => implode("\n", [
                'Received: by sendgrid.test with SMTP id jkjkdfasjkasdfj '.date('r'),
                'Message-ID: <'.Uuid::uuid().'@example.com>',
                'Content-Type: multipart/alternative; boundary=\"=-8zyIUD8oGIogZ9usA+nq\"',
            ]),
            'dkim' => 'none',
            'to' => 'Test Recipient <recipient@example.com>',
            'from' => 'Test Sender <sender@example.com>',
            'html' => '<p>Test HTML</p>',
            'text' => 'Test text',
            'sender_ip' => '127.0.0.1',
            'spam_report' =>
                'Spam detection software, running on the system \"mx.sendgrid.net\", has\nidentified this incoming email as possible spam.\n\n',
            'envelope' => json_encode(['to' => 'recipient@example.com', 'from' => 'sender@example.com']),
            'attachments' => 0,
            'subject' => 'Test Message',
            'spam_score' => '0.5',
            'charsets' => '{"to":"UTF-8","html":"utf-8","subject":"UTF-8","from":"UTF-8","text":"iso-8859-1"}',
            'SPF' => 'pass',
        ];

        $this->outboundData['to'] = ReplyEmail::generate('recipient@example.com', 'sender@example.com');
        $this->outboundData['from'] = config('mailfunnel.recipient.email');
        $this->outboundData['envelope'] = json_encode(['to' => $this->outboundData['to'], 'from' => $this->outboundData['from']]);
    }

    public function testInbound()
    {
        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertSent(InboundMail::class, function (InboundMail $mail) {
            $mail->build();
            $this->assertEquals(
                [['address' => config('mail.from.address'), 'name' => 'Test Sender \'sender@example.com\' via '.$mail->getOriginalToEmail()]],
                $mail->from
            );
            $this->assertNotNull($mail->view);
            $this->assertNotNull($mail->textView);
            $this->assertNotNull($mail->textView);

            return true;
        });

        $envelope = json_decode($this->inboundData['envelope'], true);
        $message = Message::all()->last();
        $this->assertEquals($this->inboundData['subject'], $message->subject);
        $this->assertEquals($envelope['to'], $message->address->email);
        $this->assertEquals($this->inboundData['from'], $message->from);
        $this->assertFalse($message->is_rejected);
        $this->assertEquals('0.5', $message->spam_score);
    }

    public function testInboundIsSpam()
    {
        $this->inboundData['spam_score'] = 10;

        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertNotSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_SPAM_SCORE, $message->reason);
        $this->assertEquals('10', $message->spam_score);
    }

    public function testInboundIsBlocked()
    {
        $envelope = json_decode($this->inboundData['envelope'], true);
        $address = new Address(['email' => $envelope['to']]);
        $address->is_blocked = true;
        $address->domain_id = Domain::where('domain', 'example.com')->first()->id;
        $address->save();

        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertNotSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_ADDRESS_BLOCKED, $message->reason);
        $this->assertEquals($address->id, $message->address_id);
    }

    public function testInboundNoFromName()
    {
        $envelope = json_decode($this->inboundData['envelope'], true);
        $this->inboundData['from'] = $envelope['from'];

        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertSent(InboundMail::class, function (InboundMail $mail) {
            $mail->build();
            $this->assertEquals(
                [['address' => config('mail.from.address'), 'name' => 'sender@example.com via '.$mail->getOriginalToEmail()]],
                $mail->from
            );
            $this->assertNotNull($mail->view);
            $this->assertNotNull($mail->textView);
            $this->assertNotNull($mail->textView);

            return true;
        });

        $message = Message::all()->last();
        $this->assertEquals($envelope['from'], $message->from);
        $this->assertFalse($message->is_rejected);
    }

    public function testInboundNoToName()
    {
        $envelope = json_decode($this->inboundData['envelope'], true);
        $this->inboundData['to'] = $envelope['to'];

        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertEquals($envelope['to'], $message->address->email);
        $this->assertFalse($message->is_rejected);
    }

    public function testInboundBadAuth()
    {
        $this->authHeaders['PHP_AUTH_PW'] = 'BadPassword';

        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData, $this->authHeaders);
        $response->assertStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testInboundNoAuth()
    {
        $response = $this->json('POST', '/sendgrid/inbound', $this->inboundData);
        $response->assertStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testOutbound()
    {
        $response = $this->json('POST', '/sendgrid/outbound', $this->outboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertSent(OutboundMail::class, function (OutboundMail $mail) {
            $mail->build();
            $this->assertEquals([['address' => 'recipient@example.com', 'name' => null]], $mail->from);
            $this->assertEquals([['address' => 'sender@example.com', 'name' => null]], $mail->to);

            return true;
        });
    }

    public function testOutboundNotAuthorized()
    {
        $this->outboundData['from'] = 'hacker@example.com';
        $this->outboundData['envelope'] = json_encode(['to' => $this->outboundData['to'], 'from' => $this->outboundData['from']]);

        $response = $this->json('POST', '/sendgrid/outbound', $this->outboundData, $this->authHeaders);
        $response->assertStatus(200);

        Mail::assertNotSent(OutboundMail::class);
    }

    public function testOutboundBadAuth()
    {
        $this->authHeaders['PHP_AUTH_PW'] = 'BadPassword';

        $response = $this->json('POST', '/sendgrid/outbound', $this->outboundData, $this->authHeaders);
        $response->assertStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testOutboundNoAuth()
    {
        $response = $this->json('POST', '/sendgrid/outbound', $this->outboundData);
        $response->assertStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }
}
