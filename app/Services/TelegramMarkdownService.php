<?php

namespace App\Services;

class TelegramMarkdownService
{
    /**
     * Convert standard markdown to Telegram MarkdownV2 format.
     * 
     * Telegram MarkdownV2 format:
     * - *bold* (single asterisk)
     * - _italic_ (underscore)
     * - [text](url) (links)
     * - `code` (backticks)
     * - ~strikethrough~ (tilde)
     * 
     * @param string $text The markdown text to convert
     * @return string Text formatted for Telegram MarkdownV2
     */
    public function toMarkdownV2(string $text): string
    {
        // Use a placeholder that won't be escaped (contains only letters/numbers, no special chars)
        // Using a pattern that's extremely unlikely to appear in user text
        $placeholderPrefix = 'TGMDPH';
        $counter = 0;
        $placeholders = [];
        
        // Step 1: Protect and convert markdown patterns (order matters - process more specific first)
        
        // Protect code blocks: ```language\ncode\n``` (must come before inline code)
        // Match triple backticks with optional language and code content
        $text = preg_replace_callback('/```([a-zA-Z0-9+_-]*)\n?([\s\S]*?)```/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'CB' . $counter++ . 'E';
            $code = trim($matches[2]);
            // Keep code blocks with triple backticks (Telegram will display them as text, but it's clear it's a code block)
            // Preserve the code structure with newlines
            $formatted = "```\n{$code}\n```";
            $placeholders[$key] = $formatted;
            return $key;
        }, $text);
        
        // Protect blockquotes: > text (multiline supported)
        $text = preg_replace_callback('/^>\s+(.+)$/m', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'Q' . $counter++ . 'E';
            // Convert blockquote to italic (Telegram doesn't support blockquotes)
            $content = $this->escapeMarkdownV2Content(trim($matches[1]));
            $placeholders[$key] = '_'.$content.'_';
            return $key;
        }, $text);
        
        // Protect bold: **text** (must come before single *)
        $text = preg_replace_callback('/\*\*(.+?)\*\*/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'B' . $counter++ . 'E';
            // Convert to Telegram format and escape content
            $content = $this->escapeMarkdownV2Content($matches[1]);
            $placeholders[$key] = '*'.$content.'*';
            return $key;
        }, $text);
        
        // Protect bold: __text__ (must come before single _)
        $text = preg_replace_callback('/__(.+?)__/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'B2' . $counter++ . 'E';
            $content = $this->escapeMarkdownV2Content($matches[1]);
            $placeholders[$key] = '*'.$content.'*';
            return $key;
        }, $text);
        
        // Protect links: [text](url) - do this early to avoid conflicts
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'L' . $counter++ . 'E';
            // Escape link text, but NOT the URL (Telegram doesn't escape URLs in links)
            $linkText = $this->escapeMarkdownV2Content($matches[1]);
            $linkUrl = $matches[2]; // URL is kept as-is
            $placeholders[$key] = '['.$linkText.']('.$linkUrl.')';
            return $key;
        }, $text);
        
        // Protect inline code: `code` (must come after code blocks)
        $text = preg_replace_callback('/`([^`]+)`/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'C' . $counter++ . 'E';
            // Code content doesn't need escaping in Telegram
            $placeholders[$key] = '`'.$matches[1].'`';
            return $key;
        }, $text);
        
        // Protect strikethrough: ~text~
        $text = preg_replace_callback('/~([^~]+)~/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'S' . $counter++ . 'E';
            $content = $this->escapeMarkdownV2Content($matches[1]);
            $placeholders[$key] = '~'.$content.'~';
            return $key;
        }, $text);
        
        // Protect italic: *text* (single asterisk, not part of **)
        $text = preg_replace_callback('/(?<!\*)\*([^*\n]+?)\*(?!\*)/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'I' . $counter++ . 'E';
            $content = $this->escapeMarkdownV2Content($matches[1]);
            $placeholders[$key] = '_'.$content.'_';
            return $key;
        }, $text);
        
        // Protect italic: _text_ (underscore, not part of __)
        $text = preg_replace_callback('/(?<!_)_([^_\n]+?)_(?!_)/s', function($matches) use (&$placeholders, &$counter, $placeholderPrefix) {
            $key = $placeholderPrefix . 'I2' . $counter++ . 'E';
            $content = $this->escapeMarkdownV2Content($matches[1]);
            $placeholders[$key] = '_'.$content.'_';
            return $key;
        }, $text);
        
        // Step 2: Escape all remaining special characters
        $text = $this->escapeMarkdownV2($text);
        
        // Step 3: Restore markdown patterns (in reverse order to avoid conflicts)
        // Sort by key length descending to replace longer keys first
        uksort($placeholders, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($placeholders as $key => $pattern) {
            $text = str_replace($key, $pattern, $text);
        }
        
        return $text;
    }
    
    /**
     * Escape special characters for Telegram MarkdownV2.
     * Characters that must be escaped: _ * [ ] ( ) ~ ` > # + - = | { } . !
     * 
     * @param string $text The text to escape
     * @return string Escaped text
     */
    protected function escapeMarkdownV2(string $text): string
    {
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        
        return $text;
    }
    
    /**
     * Escape content inside markdown patterns (but not the pattern delimiters themselves).
     * This is used for text inside bold, italic, etc.
     * 
     * @param string $text The text to escape
     * @return string Escaped text
     */
    protected function escapeMarkdownV2Content(string $text): string
    {
        // Escape all special characters except those that are part of the markdown pattern
        // For content, we escape everything that could break the pattern
        return $this->escapeMarkdownV2($text);
    }
}

