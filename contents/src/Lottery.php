<?php
namespace benbun\lottery;

use think\Log;
use think\Db;

// 通用抽奖工具类
class Lottery {

    const KEY_PRIZES = 'prizes';
    const KEY_ID = 'id';
    const KEY_NAME = 'name';
    const KEY_SHARE = 'share';
    const KEY_LIMIT = 'limit';
    const KEY_LIMITS = 'limits';
    const KEY_TYPE = 'type';
    const KEY_AMOUNT = 'amount';
    const KEY_NOPRIZE_SHARE = 'noprize_share';
    const KEY_USER_DRAW_LIMIT = 'user_draw_limit';
    const KEY_USER_PRIZE_LIMIT = 'user_prize_limit';

    const KEY_USER_ID = 'user_id';
    const KEY_PRIZE_ID = 'prize_id';
    const KEY_CNT = 'cnt';
    const KEY_START_T = 'start_t';
    const KEY_LIMIT_PER_USER = 'limit_per_user';

    const NAME_NOPRIZE = 'noprize';

    const ID_NOPRIZE = -1;

    const INTERVAL_TOTAL = 0;

    const TABLE_LOTTERY_PRIZE_CNT = 'lottery_prize_cnt';
    const TABLE_LOTTERY_USER_PRIZE_CNT = 'lottery_user_prize_cnt';
    const TABLE_LOTTERY_USER_DRAW_CNT = 'lottery_user_draw_cnt';
    const TABLE_LOTTERY_LOG = 'lottery_log';

    const TYPE_TOTAL = 'total';
    const TYPE_DAY = 'day';
    const TYPE_HOUR = 'hour';

    const SECONDS_PER_HOUR = 3600;

    const ERROR_CODE_INVALID_CONFIGURE = 30001;
    const ERROR_CODE_NO_PRIZE_NOW = 30002; // 现在没奖品
    const ERROR_CODE_LOTTERY_DB_ERROR = 30003;
    const ERROR_CODE_NO_DRAW_QUOTA = 30004;
    const ERROR_CODE_NO_TOTAL_PRIZE_QUOTA_FOR_USER = 30005;
    const ERROR_CODE_NO_PRIZE_AT_ALL = 30006;
    const ERROR_CODE_LOTTERY_NOT_LOTTERY_TIME = 30007;
    const ERROR_CODE_NO_PRIZE_QUOTA_FOR_USER = 30008;
    const ERROR_CODE_NO_BIG_PRIZE = 30009;//没中大奖

    private $conf = null;
    private $shareSum = 0;
    private $id2prizeInfo = null;
    private $lastException = null;
    private $now = 0;

    public function __construct($conf) {
        if (!is_array($conf)) {
            throw new LotteryException('[Lottery] invalid conf', self::ERROR_CODE_INVALID_CONFIGURE);
        }

        if (!isset($conf[self::KEY_PRIZES])) {
            throw new LotteryException('[Lottery] invalid conf: no ' . self::KEY_PRIZES, self::ERROR_CODE_INVALID_CONFIGURE);
        }

        $this->shareSum = isset($conf[self::KEY_NOPRIZE_SHARE]) ? intval($conf[self::KEY_NOPRIZE_SHARE]) : 0;
        $this->id2prizeInfo = [];
        foreach ($conf[self::KEY_PRIZES] as $id => &$prize) {
            if (!isset($prize[self::KEY_ID])) {
                $prize[self::KEY_ID] = $id;
            }
            $prizeId = $prize[self::KEY_ID];
            $this->id2prizeInfo[$prizeId] = $prize;
            $this->shareSum += intval($prize[self::KEY_SHARE]);
        }
        if ($this->shareSum <= 0) {
            throw new LotteryException('[Lottery] invalid share', self::ERROR_CODE_INVALID_CONFIGURE);
        }

        $this->conf = $conf;
        $this->now = time();
    }

    // 抽奖函数，返回奖品ID
    public function draw($userId) {
        try {
            $this->checkUserDrawCnt($userId);
            $this->incUserDrawCnt($userId);

            $prizeId = $this->calcPrizeId();
            if ($prizeId != self::ID_NOPRIZE) {
                $this->checkIntervalLotteryStartTime($prizeId);
                try {
                    Db::startTrans();
                    $this->checkTotalUserPrizeCnt($userId);
                    $this->checkTotalPrizeCnt($prizeId);
                    $this->checkIntervalPrizeCnt($prizeId);
                    $this->checkUserPrizeCnt($userId, $prizeId);
                    $this->incPrizeCnt($userId, $prizeId);
                    $this->addPrizeLog($userId, $prizeId);
                    DB::commit();
                } catch (LotteryException $e) {
                    Db::rollback();
                    Log::record("[Lottery.draw-rollback] userId: $userId, prizeId: $prizeId");
                    throw $e;
                }
            }

            Log::record('[Lottery.draw] prizeId: ' . $prizeId . ', userId: ' . $userId, Log::NOTICE);
            return $prizeId;
        } catch (LotteryException $e) {
            $this->lastException = $e;
            Log::record('[Lottery.draw-error] code: ' . $e->getCode() . ', message: ' . $e->getMessage());
            throw $e;
        }
    }

    // 根据奖品ID获取奖品信息
    public function getPrizeInfo($prizeId) {
        if (isset($this->id2prizeInfo[$prizeId])) {
            return $this->id2prizeInfo[$prizeId];
        }
        return [
            self::KEY_ID => self::ID_NOPRIZE,
            self::KEY_NAME => self::NAME_NOPRIZE,
        ];
    }

    public function getName($prizeId) {
        $info = $this->getPrizeInfo($prizeId);
        return $info[self::KEY_NAME];
    }

    public function getLastException() {
        return $this->lastException;
    }

    // --- private function ---

    //  用户+时段的抽奖次数限制（按天、活动期间）
    private function checkUserDrawCnt($userId) {
        $cnt = $this->getUserDrawCnt($userId);
        $amount = $this->conf[self::KEY_USER_DRAW_LIMIT][self::KEY_AMOUNT];
        if ($amount !== null && $cnt >= $amount) {
            throw new \Exception('no draw quota', self::ERROR_CODE_NO_DRAW_QUOTA);
        }
    }

    // 用户+时段的中奖次数限制（按天、活动期间）
    private function checkTotalUserPrizeCnt($userId) {
        $cnt = $this->getTotalUserPrizeCnt($userId);
        $amount = $this->conf[self::KEY_USER_PRIZE_LIMIT][self::KEY_AMOUNT];
        if ($amount !== null && $cnt >= $amount) {
            throw new LotteryException('no total prize quota for user', self::ERROR_CODE_NO_TOTAL_PRIZE_QUOTA_FOR_USER);
        }
    }

    // 奖品总数限制
    private function checkTotalPrizeCnt($prizeId) {
        $prizeInfo = $this->getPrizeInfo($prizeId);
        $amount = $prizeInfo[self::KEY_AMOUNT];
        $cnt = $this->getTotalPrizeCnt($prizeId);
        if ($amount !== null && $cnt >= $amount) {
            throw new LotteryException("no prize($prizeId) at all", self::ERROR_CODE_NO_PRIZE_AT_ALL);
        }
    }

    // 奖品+时段的中奖次数限制（按小时）
    private function checkIntervalPrizeCnt($prizeId) {
        $amount = $this->getIntervalPrizeAmount($prizeId);
        $cnt = $this->getIntervalPrizeCnt($prizeId);
        if ($cnt >= $amount) {
            throw new LotteryException("no prize($prizeId) quota now", self::ERROR_CODE_NO_PRIZE_NOW);
        }
    }

    // 检查是否已到本小时开奖时间
    private function checkIntervalLotteryStartTime($prizeId) {
        $amount = $this->getIntervalPrizeAmount($prizeId);
        if ($amount == 0) {
            throw new LotteryException("no prize($prizeId) now", self::ERROR_CODE_NO_PRIZE_NOW);
        }
        $start = $this->getIntervalLotteryStartTime($amount);
        $now = $this->now % self::SECONDS_PER_HOUR;
        Log::record("[Lottery.checkIntervalLotteryStartTime] prizeId: $prizeId, amount: $amount, start: $start, now: $now", Log::DEBUG);
        if ($now < $start) {
            throw new LotteryException("not lottery time for prize($prizeId)", self::ERROR_CODE_LOTTERY_NOT_LOTTERY_TIME);
        }
    }

    // 根据次数限制和小时编号随机本小时的开奖时间
    private function getIntervalLotteryStartTime($amount) {
        $end = floor(self::SECONDS_PER_HOUR / ($amount + 1));
        $hourId = floor($this->now / self::SECONDS_PER_HOUR);
        mt_srand($hourId);
        $start = mt_rand(0, $end);
        mt_srand();
        return $start;
    }

    private function getIntervalPrizeAmount($prizeId) {
        static $id2amount = [];

        if (isset($id2amount[$prizeId])) {
            return $id2amount[$prizeId];
        }

        $prizeInfo = $this->getPrizeInfo($prizeId);
        $limits = $prizeInfo[self::KEY_LIMITS];
        $limitMgr = new LimitMgr($limits);
        $amount = $limitMgr->getAmount($this->now);
        $id2amount[$prizeId] = $amount;
        Log::record("[Lottery.getIntervalPrizeAmount] prizeId: $prizeId, amount: $amount", Log::DEBUG);
        return $amount;
    }

    // 奖品+用户+时段的中奖次数限制（按天、活动期间）
    private function checkUserPrizeCnt($userId, $prizeId) {
        $prizeInfo = $this->getPrizeInfo($prizeId);
        $amount = $prizeInfo[self::KEY_LIMIT_PER_USER][self::KEY_AMOUNT];
        $cnt = $this->getUserPrizeCnt($userId, $prizeId);
        if ($amount !== null && $cnt >= $amount) {
            throw new LotteryException('no prize quota for user', self::ERROR_CODE_NO_PRIZE_QUOTA_FOR_USER);
        }
    }

    // 中奖相关计数器加1
    private function incPrizeCnt($userId, $prizeId) {
        $this->incTotalPrizeCnt($prizeId);
        $this->incIntervalPrizeCnt($prizeId);
        $this->incTotalUserPrizeCnt($userId);
        $this->incUserPrizeCnt($userId, $prizeId);
    }

    private function calcPrizeId() {
        $rand = mt_rand(0, $this->shareSum - 1);
        $shareSum = 0;
        $prizeId = self::ID_NOPRIZE;
        foreach ($this->conf[self::KEY_PRIZES] as $prize) {
            $shareSum += $prize[self::KEY_SHARE];
            if ($rand < $shareSum) {
                $prizeId = $prize[self::KEY_ID];
                break;
            }
        }
        Log::record("[Lottery.calcPrizeId] shareSum: {$this->shareSum}, rand: $rand, prizeId: $prizeId", Log::DEBUG);
        return $prizeId;
    }

    private function getInterval($type) {
        if ($type == self::TYPE_DAY) {
            $interval = 3600 * 24;
        } else if ($type == self::TYPE_HOUR) {
            $interval = 3600;
        } else {
            $interval = self::INTERVAL_TOTAL;
        }
        return $interval;
    }

    private function getStart($type) {
        $interval = $this->getInterval($type);
        if ($interval == self::INTERVAL_TOTAL) {
            return null;
        }
        // 考虑东八区
        $remainder = ($this->now + 3600 * 8) % $interval;
        $start = $this->now - $remainder;

        return date('Y-m-d H:i:s', $start);
    }

    private function getUserDrawWhere($userId) {
        $type = $this->conf[self::KEY_USER_DRAW_LIMIT][self::KEY_TYPE];
        $start = $this->getStart($type);

        $where = [
            self::KEY_USER_ID => $userId,
            self::KEY_START_T => $start,
        ];
        // $where = $this->setWhereNull($where);
        return $where;
    }

    // 获取本时段内的用户抽奖次数
    private function getUserDrawCnt($userId) {
        $where = $this->getUserDrawWhere($userId);
        $model = Db::name(self::TABLE_LOTTERY_USER_DRAW_CNT);
        $row = $model->where($where)->find();
        if (empty($row)) {
            $res = $model->insert($where);
            if ($res === false) {
                throw new LotteryException("getUserDrawCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
            }
            return 0;
        } else {
            return intval($row[self::KEY_CNT]);
        }
    }

    // 用户抽奖数加1
    private function incUserDrawCnt($userId) {
        $amount = $this->conf[self::KEY_USER_DRAW_LIMIT][self::KEY_AMOUNT];
        $where = $this->getUserDrawWhere($userId);
        $where[self::KEY_CNT] = ['lt', $amount];
        $res = Db::name(self::TABLE_LOTTERY_USER_DRAW_CNT)->where($where)->setInc(self::KEY_CNT);
        if (empty($res)) {
            throw new LotteryException("incUserDrawCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
        }
    }

    private function getTotalUserPrizeWhere($userId) {
        $type = $this->conf[self::KEY_USER_PRIZE_LIMIT][self::KEY_TYPE];
        $start = $this->getStart($type);

        $where = [
            self::KEY_USER_ID => $userId,
            self::KEY_PRIZE_ID => null,
            self::KEY_START_T => $start,
        ];
        // $where = $this->setWhereNull($where);
        return $where;
    }

    // 获取一个用户的所有中奖数
    private function getTotalUserPrizeCnt($userId) {
        $where = $this->getTotalUserPrizeWhere($userId);
        $model = Db::name(self::TABLE_LOTTERY_USER_PRIZE_CNT);
        $row = $model->where($where)->find();
        if (empty($row)) {
            return 0;
        } else {
            return intval($row[self::KEY_CNT]);
        }
    }

    // 一个用户的所有中奖数加1
    private function incTotalUserPrizeCnt($userId) {
        $amount = $this->conf[self::KEY_USER_PRIZE_LIMIT][self::KEY_AMOUNT];

        $where = $this->getTotalUserPrizeWhere($userId);
        $where[self::KEY_CNT] = ['lt', $amount];

        $model = Db::name(self::TABLE_LOTTERY_USER_PRIZE_CNT);
        $res = $model->where($where)->setInc(self::KEY_CNT);
        if ($res === 0) {
            $where[self::KEY_CNT] = 1;
            $res = $model->insert($where);
            if ($res === false) {
                throw new LotteryException("incTotalUserPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
            }
        }
    }

    private function getTotalPrizeWhere($prizeId) {
        $where = [
            self::KEY_PRIZE_ID => $prizeId,
            self::KEY_START_T => null,
        ];
        // $where = $this->setWhereNull($where);
        return $where;
    }

    private function getTotalPrizeCnt($prizeId) {
        $where = $this->getTotalPrizeWhere($prizeId);
        $model = Db::name(self::TABLE_LOTTERY_PRIZE_CNT);
        $row = $model->where($where)->find();
        if (empty($row)) {
            $res = $model->insert($where);
            if (empty($res)) {
                throw new LotteryException("getTotalPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
            }
            return 0;
        } else {
            return intval($row[self::KEY_CNT]);
        }
    }

    private function incTotalPrizeCnt($prizeId) {
        $prizeInfo = $this->getPrizeInfo($prizeId);
        $amount = $prizeInfo[self::KEY_AMOUNT];

        $where = $this->getTotalPrizeWhere($prizeId);
        $where[self::KEY_CNT] = ['lt', $amount];

        $res = Db::name(self::TABLE_LOTTERY_PRIZE_CNT)->where($where)->setInc(self::KEY_CNT);
        if (empty($res)) {
            throw new LotteryException("incTotalPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
        }
    }

    private function getIntervalPrizeWhere($prizeId) {
        // 时段控制奖品数统一按小时控制
        $type = self::TYPE_HOUR;
        $start = $this->getStart($type);

        $where = [
            self::KEY_PRIZE_ID => $prizeId,
            self::KEY_START_T => $start,
        ];
        // $where = $this->setWhereNull($where);
        return $where;
    }

    // 获取本时段奖品发放数
    private function getIntervalPrizeCnt($prizeId) {
        $where = $this->getIntervalPrizeWhere($prizeId);
        $model = Db::name(self::TABLE_LOTTERY_PRIZE_CNT);
        $row = $model->where($where)->find();
        if (empty($row)) {
            $res = $model->insert($where);
            if (empty($res)) {
                throw new LotteryException("getIntervalPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
            }
            return 0;
        } else {
            return intval($row[self::KEY_CNT]);
        }
    }

    // 本时段奖品发放数加1
    private function incIntervalPrizeCnt($prizeId) {
        $amount = $this->getIntervalPrizeAmount($prizeId);

        $where = $this->getIntervalPrizeWhere($prizeId);
        $where[self::KEY_CNT] = ['lt', $amount];

        $res = Db::name(self::TABLE_LOTTERY_PRIZE_CNT)->where($where)->setInc(self::KEY_CNT);
        if (empty($res)) {
            throw new LotteryException("incIntervalPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
        }
    }

    private function getUserPrizeWhere($userId, $prizeId) {
        $prizeInfo = $this->getPrizeInfo($prizeId);
        $type = $prizeInfo[self::KEY_LIMIT_PER_USER][self::KEY_TYPE];
        $start = $this->getStart($type);

        $where = [
            self::KEY_USER_ID => $userId,
            self::KEY_PRIZE_ID => $prizeId,
            self::KEY_START_T => $start,
        ];
        // $where = $this->setWhereNull($where);
        return $where;
    }

    // 获取一个用户的某个奖项的中奖数
    private function getUserPrizeCnt($userId, $prizeId) {
        $where = $this->getUserPrizeWhere($userId, $prizeId);
        $row = Db::name(self::TABLE_LOTTERY_USER_PRIZE_CNT)->where($where)->find();
        if (empty($row)) {
            return 0;
        } else {
            return intval($row[self::KEY_CNT]);
        }
    }

    // 一个用户的某个奖项的中奖数加1
    private function incUserPrizeCnt($userId, $prizeId) {
        $prizeInfo = $this->getPrizeInfo($prizeId);
        $amount = $prizeInfo[self::KEY_LIMIT_PER_USER][self::KEY_AMOUNT];

        $where = $this->getUserPrizeWhere($userId, $prizeId);
        $where[self::KEY_CNT] = ['lt', $amount];

        $model = Db::name(self::TABLE_LOTTERY_USER_PRIZE_CNT);
        $res = $model->where($where)->setInc(self::KEY_CNT);
        if ($res === 0) {
            $where[self::KEY_CNT] = 1;
            $res = $model->insert($where);
            if (empty($res)) {
                throw new LotteryException("incUserPrizeCnt db error", self::ERROR_CODE_LOTTERY_DB_ERROR);
            }
        }
    }

    private function addPrizeLog($userId, $prizeId) {
        Db::name(self::TABLE_LOTTERY_LOG)->insert([
            self::KEY_USER_ID => $userId,
            self::KEY_PRIZE_ID => $prizeId,
            self::KEY_NAME => $this->getName($prizeId),
        ]);
    }

    // private function setWhereNull($where) {
    //     foreach ($where as $key => $value) {
    //         if ($value === null) {
    //             $where[$key] = ['NULL'];
    //         }
    //     }
    //     return $where;
    // }
}
