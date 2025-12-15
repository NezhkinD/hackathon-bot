<?php

declare(strict_types=1);

namespace App\Tests\unit\Service\Parser;

use App\Service\Parser\JsonExportParser;
use PHPUnit\Framework\TestCase;

class JsonExportParserTest extends TestCase
{
    private JsonExportParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JsonExportParser();
    }

    public function testSupportsReturnsTrueForValidJsonWithMessages(): void
    {
        $json = json_encode(['messages' => []]);
        $this->assertTrue($this->parser->supports($json));
    }

    public function testSupportsReturnsFalseForJsonWithoutMessages(): void
    {
        $json = json_encode(['data' => []]);
        $this->assertFalse($this->parser->supports($json));
    }

    public function testSupportsReturnsFalseForInvalidJson(): void
    {
        $this->assertFalse($this->parser->supports('not valid json'));
    }

    public function testSupportsReturnsFalseForEmptyString(): void
    {
        $this->assertFalse($this->parser->supports(''));
    }

    public function testParseExtractsParticipantFromMessage(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123456',
                    'text' => 'Hello world',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);

        $participant = array_values($participants)[0];
        $this->assertEquals('John Doe', $participant->name);
        $this->assertEquals('123456', $participant->id);
        $this->assertFalse($participant->isForwarded);
    }

    public function testParseExtractsMultipleParticipants(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                ],
                [
                    'type' => 'message',
                    'from' => 'Jane Smith',
                    'from_id' => 'user456',
                    'text' => 'World',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(2, $participants);
    }

    public function testParseDeduplicatesParticipants(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                ],
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'World',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);
    }

    public function testParseExcludesDeletedAccounts(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'Deleted Account',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(0, $participants);
    }

    public function testParseExcludesNonMessageTypes(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'service',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(0, $participants);
    }

    public function testParseExcludesMessagesWithEmptyFrom(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => '',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(0, $participants);
    }

    public function testParseExtractsForwardedAuthor(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'forwarded_from' => 'Forwarded Author',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $forwardedAuthors = $result->getForwardedAuthors();
        $this->assertCount(1, $forwardedAuthors);

        $forwarded = array_values($forwardedAuthors)[0];
        $this->assertEquals('Forwarded Author', $forwarded->name);
        $this->assertTrue($forwarded->isForwarded);
        $this->assertStringStartsWith('fwd_', $forwarded->id);
    }

    public function testParseExcludesDeletedAccountsFromForwarded(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'forwarded_from' => 'Deleted Account',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $forwardedAuthors = $result->getForwardedAuthors();
        $this->assertCount(0, $forwardedAuthors);
    }

    public function testParseExtractsMentionsFromTextEntities(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                    'text_entities' => [
                        [
                            'type' => 'mention',
                            'text' => '@username123',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(1, $mentions);
        $this->assertArrayHasKey('username123', $mentions);
    }

    public function testParseExtractsMentionsFromTextString(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello @testuser123 and @another_user',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(2, $mentions);
        $this->assertArrayHasKey('testuser123', $mentions);
        $this->assertArrayHasKey('another_user', $mentions);
    }

    public function testParseIgnoresShortMentions(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello @user',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(0, $mentions);
    }

    public function testParseExtractsChannels(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'Test Channel',
                    'from_id' => 'channel123456',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $channels = $result->getChannels();
        $this->assertCount(1, $channels);
        $this->assertArrayHasKey('123456', $channels);
        $this->assertEquals('Test Channel', $channels['123456']);
    }

    public function testParseNormalizesUserId(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user987654321',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $participant = array_values($participants)[0];
        $this->assertEquals('987654321', $participant->id);
    }

    public function testParseHandlesMessageWithoutFromId(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'text' => 'Hello',
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);

        $participant = array_values($participants)[0];
        $this->assertEquals('John Doe', $participant->name);
        $this->assertNotEmpty($participant->id);
    }

    public function testParseReturnsEmptyResultForEmptyMessages(): void
    {
        $json = json_encode(['messages' => []]);

        $result = $this->parser->parse($json);

        $this->assertCount(0, $result->getParticipants());
        $this->assertCount(0, $result->getMentions());
        $this->assertCount(0, $result->getChannels());
        $this->assertCount(0, $result->getForwardedAuthors());
    }

    public function testParseThrowsExceptionForInvalidJson(): void
    {
        $this->expectException(\JsonException::class);
        $this->parser->parse('invalid json');
    }

    public function testParseCombinesMentionsFromEntitiesAndText(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello @user_from_text',
                    'text_entities' => [
                        [
                            'type' => 'mention',
                            'text' => '@user_from_entity',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(2, $mentions);
        $this->assertArrayHasKey('user_from_text', $mentions);
        $this->assertArrayHasKey('user_from_entity', $mentions);
    }

    public function testParseDeduplicatesMentions(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello @sameuser12',
                    'text_entities' => [
                        [
                            'type' => 'mention',
                            'text' => '@sameuser12',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(1, $mentions);
    }

    public function testParseHandlesNonStringText(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => [
                        ['type' => 'text', 'text' => 'Some text'],
                        ['type' => 'mention', 'text' => '@mention'],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $participants = $result->getParticipants();
        $this->assertCount(1, $participants);
    }

    public function testParseIgnoresNonMentionEntities(): void
    {
        $json = json_encode([
            'messages' => [
                [
                    'type' => 'message',
                    'from' => 'John Doe',
                    'from_id' => 'user123',
                    'text' => 'Hello',
                    'text_entities' => [
                        [
                            'type' => 'bold',
                            'text' => 'bold text',
                        ],
                        [
                            'type' => 'link',
                            'text' => 'http://example.com',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($json);

        $mentions = $result->getMentions();
        $this->assertCount(0, $mentions);
    }
}
