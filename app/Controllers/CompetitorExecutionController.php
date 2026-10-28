<?php
class CompetitorExecutionController extends Controller
{
    private function userId(): int
    {
        return (int)($this->user['id']??0);
    }
    public function policy(array $params=[]): array
    {
        if (!$this->userId()) return $this->error('Unauthorized',401);
        try {return $this->success((new CompetitorSiteExecutionService())->policy($this->userId(),(int)$params['id']));}
        catch(Throwable $e){return $this->error('Website not found',404);}
    }
    public function savePolicy(array $params=[]): array
    {
        if (!$this->userId()) return $this->error('Unauthorized',401);
        $auto=$this->get('competitor_auto_apply',false);
        if (!in_array($auto,[true,false,0,1,'0','1'],true)) return $this->error('Invalid automatic execution setting',422);
        try {return $this->success((new CompetitorSiteExecutionService())->savePolicy($this->userId(),(int)$params['id'],(string)$this->get('output_language','auto'),(bool)$auto));}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){return $this->error('Unable to save this website policy',409);}
    }
    public function execute(array $params=[]): array
    {
        if (!$this->userId()) return $this->error('Unauthorized',401);
        if ($limited=$this->rateLimitGuard('user','ai',20,60)) return $limited;
        try {return $this->success((new CompetitorSiteExecutionService())->request($this->userId(),(int)$params['id']));}
        catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage(),409),409);}
    }
    public function status(array $params=[]): array
    {
        if (!$this->userId()) return $this->error('Unauthorized',401);
        try {return $this->success((new CompetitorSiteExecutionService())->status($this->userId(),(int)$params['id']));}
        catch(Throwable $e){return $this->error('Execution not found',404);}
    }
    public function rollback(array $params=[]): array
    {
        if (!$this->userId()) return $this->error('Unauthorized',401);
        try {return $this->success((new CompetitorSiteExecutionService())->rollback($this->userId(),(int)$params['id']));}
        catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage(),409),409);}
    }
}
