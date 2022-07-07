<?php

declare(strict_types=1);

namespace MariaStan\Parser;

use MariaStan\Parser\Exception\LexerException;

use function array_slice;
use function assert;
use function constant;
use function is_int;
use function preg_match_all;
use function print_r;
use function str_starts_with;
use function strtoupper;

use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

class MariaDbLexer
{
	/** @return array<Token> */
	public function tokenize(string $input): array
	{
		// Inspired by
		// https://github.com/nette/latte/blob/13c81eeaafdbce06e6cb57cbd0c1190622d8a1bd/src/Latte/Compiler/TagLexer.php
		// Missing features:
		// - executable comments
		// - identifiers beginning with a numeral are probably not 100% correct.
		$re = <<<'XX'
			~(?J)(?n)   # allow duplicate named groups, no auto capture
			(?<whitespace>  [ \t\r\n]+  )|
			( (?<string_single>  '  )  (?<rest>  ( \\. | '' | [^'\\] )*  '  )?  )|
			( (?<string_double>  "  )  (?<rest>  ( \\. | "" | [^"\\] )*  "  )?  )|
			( (?<c_comment>  /\*  )   (?<rest>  .*?\*/  )?  )|
			( (?<line_comment>  (\#|--[ ])  )   (?<rest>  [^\r\n]*[\r\n]  )?  )|
			# All 2B UTF-8 characters except NUL (\x60 is `)
			( (?<quoted_identifier> ` ) (?<rest>  ( `` | [\x01-\x5F\x61-\x{FFFF}] )*  `  )?  )|
			(?<literal_bin>
				([bB] ' [0-1]+ ')|
				(0b[01]+)
			)|
			(?<literal_hex>
				([xX] ' [0-9a-fA-F]+ ')|
				(0x [0-9a-fA-F]+)
			)|
			(?<literal_float>
				((?&lnum) | (?&dnum)) [eE][+-]? (?&lnum)|
				(?<dnum>   (?&lnum)? \. (?&lnum) | (?&lnum) \. (?&lnum)?  )
			)|
			(?<literal_int> (?<lnum>  [0-9]+(_[0-9]+)*  ) )|
			# Identifier names may begin with a numeral, but can't only contain numerals unless quoted.
			(?<identifier> [0-9a-zA-Z$_\x80-\x{FFFF}]*[a-zA-Z$_\x80-\x{FFFF}][0-9a-zA-Z$_\x80-\x{FFFF}]*  )|
			(?<op_colon_assign> := )|
			(?<op_shift_left> << )|
			(?<op_shift_right> >> )|
			(?<op_ne> ( != | <> ) )|
			(?<op_lte> <= )|
			(?<op_gte> >= )|
			(?<op_null_safe> <=> )|
			(?<op_logic_and> && )|
			(?<op_logic_or> \|\| )|
			(?<char>  [;,.|^&+/*=%!\~$<>?@()-]  )|
			(?<badchar>  .  )
			~xsAu
			XX;

		$tokens = [];
		$matches = [];
		preg_match_all($re, $input, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
		$keywordMap = TokenTypeEnum::getKeywordsMap();

		foreach ($matches as $m) {
			if (isset($m['whitespace']) || isset($m['line_comment'])) {
				continue;
			}

			if (isset($m['string_single']) || isset($m['string_double'])) {
				$tokens[] = new Token(TokenTypeEnum::LITERAL_STRING, $m[0]);
			} elseif (isset($m['c_comment'])) {
				if (isset($m['rest'])) {
					continue;
				}

				throw new LexerException('Unterminated C-comment');
			} elseif (isset($m['quoted_identifier'])) {
				$tokens[] = new Token(TokenTypeEnum::IDENTIFIER, $m[0]);
			} elseif (isset($m['literal_bin'])) {
				$tokens[] = new Token(TokenTypeEnum::LITERAL_BIN, $m[0]);
			} elseif (isset($m['literal_hex'])) {
				$tokens[] = new Token(TokenTypeEnum::LITERAL_HEX, $m[0]);
			} elseif (isset($m['literal_float'])) {
				$tokens[] = new Token(TokenTypeEnum::LITERAL_FLOAT, $m[0]);
			} elseif (isset($m['literal_int'])) {
				$tokens[] = new Token(TokenTypeEnum::LITERAL_INT, $m[0]);
			} elseif (isset($m['identifier'])) {
				$tokens[] = new Token($keywordMap[strtoupper($m[0])] ?? TokenTypeEnum::IDENTIFIER, $m[0]);
			} elseif (isset($m['char'])) {
				$tokens[] = new Token(TokenTypeEnum::SINGLE_CHAR, $m['char']);
			} elseif (isset($m['badchar'])) {
				throw new LexerException("Unexpected character '{$m['badchar']}'");
			} else {
				foreach ($m as $type => $text) {
					if ($text === null || is_int($type) || ! str_starts_with($type, 'op_')) {
						continue;
					}

					$type = constant(TokenTypeEnum::class . '::' . strtoupper($type));
					assert($type instanceof TokenTypeEnum);
					$tokens[] = new Token($type, $m[0]);

					continue 2;
				}

				throw new LexerException(
					'Unmatched token: ' . print_r($m, true)
						. ' after: ' . print_r(array_slice($tokens, -5), true),
				);
			}
		}

		$tokens[] = new Token(TokenTypeEnum::END_OF_INPUT, '');

		return $tokens;
	}
}
