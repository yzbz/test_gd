<?php

namespace benbun\lottery;

// 次数限制解析器
// 格式参考了crontab的格式： 月 天 时 周 #数目
// 样例： '11 1 8-20 * #2', '11 1,2 8-20 * #2', '11 1,2,5-9 8-20 * #2'
class Limit {

    const NUM_TOKENS = 5;
    const NUM_UNITS = 4;

    const SYMBOL_COMMA = ',';
    const SYMBOL_HYPHEN = '-';
    const SYMBOL_STAR = '*';

    private $units;
    private $amount;

    public function __construct($str) {
        $this->parse($str);
    }

    public function parse($str) {
        // TODO: regex check

        $toks = explode(' ', $str);
        if (count($toks) != self::NUM_TOKENS) {
            throw new LotteryException("Invalid number of tokens", 10251);
        }

        $units = array();
        $units[0] = $this->parseUnit($toks[0], 1, 12);
        $units[1] = $this->parseUnit($toks[1], 1, 31);
        $units[2] = $this->parseUnit($toks[2], 0, 23);
        $units[3] = $this->parseUnit($toks[3], 1, 7);
        $this->units = $units;
        $this->amount = $this->parseAmount($toks[self::NUM_TOKENS - 1]);
    }

    // 获取时间对应的限制数目
    public function getAmount($time = null) {
        if ($time == null) {
            $time = time();
        }

        $target = $this->parseTimestamp($time);

        for ($i = 0; $i < self::NUM_UNITS; ++$i) {
            if ($this->units[$i] && !in_array($target[$i], $this->units[$i])) {
                return false;
            }
        }
        return $this->amount;
    }

    private function parseAmount($str) {
        if ($str[0] == '#') {
            $str = substr($str, 1);
        }
        return intval($str);
    }

    private function parseUnit($str, $min, $max) {
        if (strpos($str, self::SYMBOL_COMMA) != false) {
            $tokens = explode(self::SYMBOL_COMMA, $str);
            $nums = array();
            foreach ($tokens as $token) {
                $newNums = $this->parseUnit($token, $min, $max);
                $nums = array_merge($nums, $newNums);
            }
            return $nums;
        }

        if (strpos($str, self::SYMBOL_HYPHEN) != false) {
            list($lower, $upper) = explode(self::SYMBOL_HYPHEN, $str);
            if ($lower > $upper) {
                throw new LotteryException("Invalid -: $str", 10252);
            }
            if ($lower < $min || $upper > $max) {
                throw new LotteryException("Invalid -: $str", 10252);
            }
            return range($lower, $upper);
        }

        if ($str == self::SYMBOL_STAR) {
            return array();
        }

        $num = intval($str);
        if ($num < $min || $num > $max) {
            throw new LotteryException("Invalid value: $str", 10253);
        }
        return array($num);
    }

    private function parseTimestamp($time) {
        return explode('-', date('n-j-G-w', $time));
    }

}
