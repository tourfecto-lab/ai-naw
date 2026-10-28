<?php
/** Resume by task ID, with bounded work per job and no skipped eligible task. */
final class SeoWorkWindow {
    public int $cursor;private int $processed=0;private int $limit;private float $deadline;
    public function __construct(int $cursor=0,int $limit=8,float $seconds=65,?float $start=null){$this->cursor=max(0,$cursor);$this->limit=$limit;$this->deadline=($start??microtime(true))+$seconds;}
    public function accept(int $id,bool $eligible,?float $now=null): bool {
        if($id<=$this->cursor)return true;
        if($eligible&&($this->processed>=$this->limit||($now??microtime(true))>=$this->deadline))return false;
        $this->cursor=$id;if($eligible)$this->processed++;return true;
    }
}
