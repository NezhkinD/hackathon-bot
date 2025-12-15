<?php

declare(strict_types=1);

namespace App\Tests\unit\Service\Parser;

use App\Service\Parser\HtmlExportParser;
use PHPUnit\Framework\TestCase;

class HtmlExportParserTest extends TestCase
{
    private HtmlExportParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HtmlExportParser();
    }

    public function testSupportsReturnsTrueForMessageClass(): void
    {
        $html = '<div class="message">Test</div>';
        $this->assertTrue($this->parser->supports($html));
    }

    public function testSupportsReturnsTrueForTgmeWidgetMessage(): void
    {
        $html = '<div class="tgme_widget_message">Test</div>';
        $this->assertTrue($this->parser->supports($html));
    }

    public function testSupportsReturnsTrueForHistory(): void
    {
        $html = '<div class="history">Test</div>';
        $this->assertTrue($this->parser->supports($html));
    }

    public function testSupportsReturnsFalseForNonMatchingContent(): void
    {
        $html = '<div class="some-other-class">Test</div>';
        $this->assertFalse($this->parser->supports($html));
    }

    public function testSupportsReturnsFalseForEmptyContent(): void
    {
        $this->assertFalse($this->parser->supports(''));
    }

    public function testParseExtractsParticipantFromMessage(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="text">Hello world</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);

        $participant = array_values($participants)[0];
        $this->assertEquals('John Doe', $participant->name);
        $this->assertFalse($participant->isForwarded);
    }

    public function testParseExtractsMultipleParticipants(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
            </div>
        </div>
        <div class="message">
            <div class="body">
                <div class="from_name">Jane Smith</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(2, $participants);
    }

    public function testParseDeduplicatesParticipants(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
            </div>
        </div>
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);
    }

    public function testParseExcludesDeletedAccounts(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">Deleted Account</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(0, $participants);
    }

    public function testParseExtractsForwardedAuthor(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="forwarded">
                    <div class="from_name">Forwarded Author</div>
                </div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $forwardedAuthors = $result->getForwardedAuthors();
        $this->assertCount(1, $forwardedAuthors);

        $forwarded = array_values($forwardedAuthors)[0];
        $this->assertEquals('Forwarded Author', $forwarded->name);
        $this->assertTrue($forwarded->isForwarded);
    }

    public function testParseExcludesDeletedAccountsFromForwarded(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="forwarded">
                    <div class="from_name">Deleted Account</div>
                </div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $forwardedAuthors = $result->getForwardedAuthors();
        $this->assertCount(0, $forwardedAuthors);
    }

    public function testParseExtractsMentionsFromText(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="text">Hello @username123 and @another_user</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $mentions = $result->getMentions();
        $this->assertCount(2, $mentions);
        $this->assertArrayHasKey('username123', $mentions);
        $this->assertArrayHasKey('another_user', $mentions);
    }

    public function testParseIgnoresShortMentions(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="text">Hello @user</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $mentions = $result->getMentions();
        $this->assertCount(0, $mentions);
    }

    public function testParseExtractsChannelLinks(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="text">
                    Check out <a href="https://t.me/mychannel">this channel</a>
                </div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $channels = $result->getChannels();
        $this->assertCount(1, $channels);
        $this->assertArrayHasKey('mychannel', $channels);
    }

    public function testParseIgnoresServiceTelegramLinks(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">John Doe</div>
                <div class="text">
                    <a href="https://t.me/joinchat/abc123">Join</a>
                    <a href="https://t.me/share/url">Share</a>
                    <a href="https://t.me/addstickers/pack">Stickers</a>
                </div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $channels = $result->getChannels();
        $this->assertCount(0, $channels);
    }

    public function testParseCleansNameWithDateTime(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="forwarded">
                <div class="from_name">Author Name 07.12.2025 20:03:29</div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $forwardedAuthors = $result->getForwardedAuthors();
        $this->assertCount(1, $forwardedAuthors);

        $forwarded = array_values($forwardedAuthors)[0];
        $this->assertEquals('Author Name', $forwarded->name);
    }

    public function testParseHandlesTgmeWidgetMessageFormat(): void
    {
        $html = <<<HTML
        <div class="tgme_widget_message">
            <div class="tgme_widget_message_owner_name">Widget User</div>
            <div class="tgme_widget_message_text">Hello @testuser123</div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);

        $participant = array_values($participants)[0];
        $this->assertEquals('Widget User', $participant->name);

        $mentions = $result->getMentions();
        $this->assertArrayHasKey('testuser123', $mentions);
    }

    public function testParseReturnsEmptyResultForEmptyHtml(): void
    {
        $result = $this->parser->parse('<html></html>');

        $this->assertCount(0, $result->getParticipants());
        $this->assertCount(0, $result->getMentions());
        $this->assertCount(0, $result->getChannels());
        $this->assertCount(0, $result->getForwardedAuthors());
    }

    public function testParseHandlesEmptyFromName(): void
    {
        $html = <<<HTML
        <div class="message">
            <div class="body">
                <div class="from_name">   </div>
            </div>
        </div>
        HTML;

        $result = $this->parser->parse($html);

        $participants = $result->getParticipants();
        $this->assertCount(0, $participants);
    }
}
