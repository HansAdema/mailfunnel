<?php

namespace Test\Mail;

use App\Mail\InboundMail;
use App\Models\Address;
use App\Models\Message;
use App\ReplyEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Lumen\Testing\DatabaseMigrations;
use ReflectionClass;

class InboundMailTest extends \TestCase
{
    use ForwardableTest, DatabaseMigrations;

    /**
     * @var InboundMail
     */
    protected $inboundMail;

    public function setUp()
    {
        parent::setUp();

        $this->inboundMail = new InboundMail('phpunit');
        $this->inboundMail->setOriginalTo('Test Recipient <recipient@example.com>');
        $this->inboundMail->setOriginalFrom('Test Sender <sender@example.com>');
        $this->inboundMail->subject('Test Subject');

        Mail::fake();

//        DB::beginTransaction();
    }

    public function tearDown()
    {
        parent::tearDown();

//        DB::rollback();
    }

    public function testBuildSetsReplyToAddress()
    {
        Mail::send($this->inboundMail);

        $this->assertSent(InboundMail::class, function (InboundMail $mail) {
            $expected = [
                'address' => ReplyEmail::generate('Test Recipient <recipient@example.com>', 'Test Sender <sender@example.com>'),
                'name' => null,
            ];

            $reflection = new ReflectionClass($mail);
            $property = $reflection->getProperty('replyTo');
            $property->setAccessible(true);

            $this->assertEquals([$expected], $property->getValue($mail));

            return true;
        });
    }

    public function testBuildSetsToAddress()
    {
        Mail::send($this->inboundMail);

        $this->assertSent(InboundMail::class, function (InboundMail $mail) {
            $expected = [
                'address' => config('mailfunnel.recipient.email'),
                'name' => config('mailfunnel.recipient.name'),
            ];

            $this->assertEquals([$expected], $mail->to);

            return true;
        });
    }

    public function testBuildSetsFromAddress()
    {
        Mail::send($this->inboundMail);

        $this->assertSent(InboundMail::class, function (InboundMail $mail) {
            $expected = [
                'address' => config('mail.from.address'),
                'name' => $mail->getSafeOriginalFrom() . ' via ' . $mail->getOriginalToEmail(),
            ];

            $this->assertEquals([$expected], $mail->from);

            return true;
        });
    }

    public function testGetSafeOriginalFrom()
    {
        $this->inboundMail->setOriginalFrom('Test Sender <sender@example.com>');
        $this->assertEquals("Test Sender 'sender@example.com'", $this->inboundMail->getSafeOriginalFrom());
    }

    public function testGetSafeOriginalFromWithoutName()
    {
        $this->inboundMail->setOriginalFrom('anonymous@example.com');
        $this->assertEquals('anonymous@example.com', $this->inboundMail->getSafeOriginalFrom());
    }

    public function testGetOriginalToEmail()
    {
        $this->inboundMail->setOriginalTo('Test Receiver <receiver@example.com>');
        $this->assertEquals('receiver@example.com', $this->inboundMail->getOriginalToEmail());
    }

    public function testGetOriginalToEmailWithoutName()
    {
        $this->inboundMail->setOriginalTo('receiver2@example.com');
        $this->assertEquals('receiver2@example.com', $this->inboundMail->getOriginalToEmail());
    }

    public function testValidate()
    {
        $this->assertTrue($this->inboundMail->validate([]));

        $message = Message::all()->last();
        $this->assertFalse($message->is_rejected);
        $this->assertEquals(null, $message->reason);
    }

    public function testValidateAddressAlreadyExists()
    {
        $address = Address::create(['email' => $this->inboundMail->getOriginalToEmail()]);

        $this->assertTrue($this->inboundMail->validate([]));

        $message = Message::all()->last();
        $this->assertEquals($address->id, $message->address_id);
    }

    public function testValidateAddressIsBlocked()
    {
        $address = new Address(['email' => $this->inboundMail->getOriginalToEmail()]);
        $address->is_blocked = true;
        $address->saveOrFail();

        $this->assertFalse($this->inboundMail->validate([]));
        $message = Message::all()->last();
        $this->assertEquals($address->id, $message->address_id);
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_ADDRESS_BLOCKED, $message->reason);
    }

    public function testValidateMessageIsSpam()
    {
        $this->inboundMail->setSpamScore(PHP_INT_MAX);

        $this->assertFalse($this->inboundMail->validate([]));

        $message = Message::all()->last();
        $this->assertTrue($message->is_rejected);
        $this->assertEquals(Message::REASON_SPAM_SCORE, $message->reason);
    }

    public function testValidateLogsRequestContents()
    {
        Log::shouldReceive('info')->once()->with('Received message for provider phpunit', ['foo' => 'bar', 'fux' => 'baz']);

        $this->assertTrue($this->inboundMail->validate(['foo' => 'bar', 'fux' => 'baz']));
    }

    public function getForwardable()
    {
        return $this->inboundMail;
    }
}
