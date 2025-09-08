<?php
class Score {
    private $scoreD1, $scoreD2, $scoreD3, $scoreD4;
    private $scoreA1, $scoreA2, $scoreA3;
    private $scoreE1, $scoreE2, $scoreE3;
    private $technicalDeduction;
    private $gymnastID;
    private $judgeID;
    private $scoreID;

    public function __construct($scoreD1 = 0, $scoreD2 = 0, $scoreD3 = 0, $scoreD4 = 0, 
                              $scoreA1 = 0, $scoreA2 = 0, $scoreA3 = 0,
                              $scoreE1 = 0, $scoreE2 = 0, $scoreE3 = 0, 
                              $technicalDeduction = 0, $gymnastID = 0, $judgeID = 0) {
        $this->scoreD1 = $scoreD1;
        $this->scoreD2 = $scoreD2;
        $this->scoreD3 = $scoreD3;
        $this->scoreD4 = $scoreD4;
        $this->scoreA1 = $scoreA1;
        $this->scoreA2 = $scoreA2;
        $this->scoreA3 = $scoreA3;
        $this->scoreE1 = $scoreE1;
        $this->scoreE2 = $scoreE2;
        $this->scoreE3 = $scoreE3;
        $this->technicalDeduction = $technicalDeduction;
        $this->gymnastID = $gymnastID;
        $this->judgeID = $judgeID;
    }

    public function getAverageD1andD2() {
        if ($this->scoreD1 == 0) {
            return $this->scoreD2;
        } else if ($this->scoreD2 == 0) {
            return $this->scoreD1;
        } else {
            return ($this->scoreD1 + $this->scoreD2) / 2;
        }
    }

    public function getAverageD3andD4() {
        if ($this->scoreD3 == 0) {
            return $this->scoreD4;
        } else if ($this->scoreD4 == 0) {
            return $this->scoreD3;
        } else {
            return ($this->scoreD3 + $this->scoreD4) / 2;
        }
    }

    public function totalScoreD() {
        return $this->getAverageD1andD2() + $this->getAverageD3andD4();
    }

    public function getMiddleAScore() {
        if ($this->scoreA1 == 0 && $this->scoreA2 == 0) {
            return $this->scoreA3;
        } else if ($this->scoreA1 == 0 && $this->scoreA3 == 0) {
            return $this->scoreA2;
        } else if ($this->scoreA2 == 0 && $this->scoreA3 == 0) {
            return $this->scoreA1;
        } else {
            $aScores = [$this->scoreA1, $this->scoreA2, $this->scoreA3];
            sort($aScores);
            $middleIndex = count($aScores) / 2;
            return $aScores[(int)$middleIndex];
        }
    }

    public function getMiddleEScore() {
        if ($this->scoreE1 == 0 && $this->scoreE2 == 0) {
            return $this->scoreE3;
        } else if ($this->scoreE1 == 0 && $this->scoreE3 == 0) {
            return $this->scoreE2;
        } else if ($this->scoreE2 == 0 && $this->scoreE3 == 0) {
            return $this->scoreE1;
        } else {
            $eScores = [$this->scoreE1, $this->scoreE2, $this->scoreE3];
            sort($eScores);
            $middleIndex = count($eScores) / 2;
            return $eScores[(int)$middleIndex];
        }
    }

    public function getTotalDplusE() {
        return $this->getMiddleEScore() + $this->getMiddleAScore();
    }

    public function getTotalScore() {
        $total = $this->totalScoreD() + 10 - $this->getMiddleEScore() + 10 - $this->getMiddleAScore();
        return number_format($total, 2);
    }

    public function getFinalScore() {
        return (float)$this->getTotalScore() - $this->technicalDeduction;
    }

    // Getters and Setters
    public function getScoreD1() { return $this->scoreD1; }
    public function getScoreD2() { return $this->scoreD2; }
    public function getScoreD3() { return $this->scoreD3; }
    public function getScoreD4() { return $this->scoreD4; }
    public function getScoreA1() { return $this->scoreA1; }
    public function getScoreA2() { return $this->scoreA2; }
    public function getScoreA3() { return $this->scoreA3; }
    public function getScoreE1() { return $this->scoreE1; }
    public function getScoreE2() { return $this->scoreE2; }
    public function getScoreE3() { return $this->scoreE3; }
    public function getTechnicalDeduction() { return $this->technicalDeduction; }
    public function getGymnastID() { return $this->gymnastID; }
    public function getJudgeID() { return $this->judgeID; }
    public function getScoreID() { return $this->scoreID; }

    public function setScoreID($scoreID) { $this->scoreID = $scoreID; }
}
?>