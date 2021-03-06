<?php

namespace Test\Http\Controllers;

use App\Mail\InboundMail;
use App\Mail\OutboundMail;
use App\Models\Address;
use App\Models\Message;
use App\ReplyEmail;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Mail;
use Laravel\Lumen\Testing\DatabaseMigrations;

class PostmarkControllerTest extends \TestCase
{
    use DatabaseMigrations;

    protected $inboundData;
    protected $outboundData;
    protected $authHeaders;

    public function setUp()
    {
        parent::setUp();

        Mail::fake();

        $this->authHeaders = [
            'PHP_AUTH_USER' => 'TestUser',
            'PHP_AUTH_PW' => 'TestPassword'
        ];

        $this->inboundData = $this->outboundData = [
            'FromName' => 'Test Sender',
            'From' => 'sender@example.com',
            'FromFull' => [
                'Email' => 'sender@example.com',
                'Name' => 'Test Sender',
                'MailboxHash' => '',
            ],
            'ToName' => 'Test Receiver',
            'To' => 'receiver@example.com',
            'ToFull' => [[
                'Email' => 'receiver@example.com',
                'Name' => '',
                'MailboxHash' => '',
            ]],
            'Cc' => '',
            'CcFull' => [],
            'Bcc' => '',
            'BccFull' => [],
            'OriginalRecipient' => 'receiver@example.com',
            'Subject' => 'Test Message',
            'MessageID' => Uuid::uuid(),
            'ReplyTo' => '',
            'MailboxHash' => '',
            'Date' => date('r'),
            'TextBody' => 'Test Message',
            'HtmlBody' => '<p>Test Message</p>',
            'StrippedTextReply' => '',
            'Tag' => '',
            'Headers' => [
                [
                    'Name' => 'Received',
                    'Value' => 'by testserver.example (Testmailer); '.date('r'),
                ],
                [
                    'Name' => 'X-Spam-Checker-Version',
                    'Value' => 'SpamAssassin',
                ],
                [
                    'Name' => 'X-Spam-Status',
                    'Value' => 'No',
                ],
                [
                    'Name' => 'X-Spam-Score',
                    'Value' => '0.5',
                ],
            ],
            'Attachments' => [
                ['Name' => 'test.txt', 'Content' => base64_encode('This is a test attachments')],
            ],
        ];

        $this->outboundData['To'] = $this->outboundData['ToFull'][0]['Email'] =
            ReplyEmail::generate('recipient@example.com', 'sender@example.com');
        $this->outboundData['From'] = $this->outboundData['FromFull']['Email'] = config('mailfunnel.recipient.email');
    }

    public function testInbound()
    {
        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseOk();

        $this->assertSent(InboundMail::class, function (InboundMail $mail) {
            $this->assertEquals(
                [['address' => config('mail.from.address'), 'name' => 'Test Sender \'sender@example.com\' via '.$mail->getOriginalToEmail()]],
                $mail->from
            );
            $this->assertNotNull($mail->view);
            $this->assertNotNull($mail->textView);
            $this->assertNotNull($mail->textView);

            return true;
        });

        $message = Message::all()->last();
        $this->assertEquals($this->inboundData['Subject'], $message->subject);
        $this->assertEquals($this->inboundData['To'], $message->address->email);
        $this->assertEquals("{$this->inboundData['FromName']} <{$this->inboundData['From']}>", $message->from);
        $this->assertFalse($message->is_rejected);
        $this->assertEquals('0.5', $message->spam_score);
    }

    public function testInboundIsSpam()
    {
        array_walk($this->inboundData['Headers'], function(&$value) {
            if ($value['Name'] == 'X-Spam-Score') {
                $value['Value'] = '10';
            }
        });

        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseOk();

        Mail::assertNotSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_SPAM_SCORE, $message->reason);
        $this->assertEquals('10', $message->spam_score);
    }

    public function testInboundIsBlocked()
    {
        $address = new Address(['email' => $this->inboundData['To']]);
        $address->is_blocked = true;
        $address->saveOrFail();

        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseOk();

        Mail::assertNotSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_ADDRESS_BLOCKED, $message->reason);
        $this->assertEquals($address->id, $message->address_id);
    }

    public function testInboundNoFromName()
    {
        $this->inboundData['FromName'] = $this->inboundData['FromFull']['Name'] = '';

        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseOk();

        $this->assertSent(InboundMail::class, function (InboundMail $mail) {
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
        $this->assertEquals($this->inboundData['From'], $message->from);
        $this->assertFalse($message->is_rejected);
    }

    public function testInboundNoToName()
    {
        $this->inboundData['ToName'] = $this->inboundData['ToFull'][0]['Name'] = '';

        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseOk();

        Mail::assertSent(InboundMail::class);

        $message = Message::all()->last();
        $this->assertEquals($this->inboundData['To'], $message->address->email);
        $this->assertFalse($message->is_rejected);
    }

    public function testInboundBadAuth()
    {
        $this->authHeaders['PHP_AUTH_PW'] = 'BadPassword';

        $this->json('POST', '/postmark/inbound', $this->inboundData, $this->authHeaders);
        $this->assertResponseStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testInboundNoAuth()
    {
        $this->json('POST', '/postmark/inbound', $this->inboundData);
        $this->assertResponseStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testOutbound()
    {
        $this->json('POST', '/postmark/outbound', $this->outboundData, $this->authHeaders);
        $this->assertResponseOk();

        $this->assertSent(OutboundMail::class, function (OutboundMail $mail) {
            $this->assertEquals([['address' => 'recipient@example.com', 'name' => null]], $mail->from);
            $this->assertEquals([['address' => 'sender@example.com', 'name' => null]], $mail->to);

            return true;
        });
    }

    public function testOutboundNotAuthorized()
    {
        $this->outboundData['From'] = $this->outboundData['FromFull']['Email'] = 'hacker@example.com';

        $this->json('POST', '/postmark/outbound', $this->outboundData, $this->authHeaders);
        $this->assertResponseOk();

        Mail::assertNotSent(OutboundMail::class);
    }

    public function testOutboundBadAuth()
    {
        $this->authHeaders['PHP_AUTH_PW'] = 'BadPassword';

        $this->json('POST', '/postmark/outbound', $this->outboundData, $this->authHeaders);
        $this->assertResponseStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }

    public function testOutboundNoAuth()
    {
        $this->json('POST', '/postmark/outbound', $this->outboundData);
        $this->assertResponseStatus(403);

        Mail::assertNotSent(InboundMail::class);
    }
}
