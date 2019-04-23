<?php

namespace benbun\lottery;

// 次数限制管理器，管理一个limit列表
class LimitMgr {

    private $rules = null;

    public function __construct($rules) {
        $this->rules = $rules;
    }

    // 获取时间对应的限制数目
    public function getAmount($time = null) {
        if ($time == null) {
            $time = time();
        }

        foreach ($this->rules as $rule) {
            $limit = $this->getLimit($rule);
            $amount = $limit->getAmount($time);
            if ($amount !== false) {
                return $amount;
            }
        }
        return 0;
    }

    private static function getLimit($rule) {
        return new Limit($rule);
    }

}
