<?php
/** Owner-scoped organization and reversible deletion; assets needed by publishers stay available. */
class CreativeLibrary
{
    public static function folders(int $userId): array
    {
        $s=Database::getInstance()->getConnection()->prepare('SELECT id,name FROM creative_folders WHERE user_id=? ORDER BY name,id');$s->execute([$userId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function createFolder(int $userId,string $name): int
    {
        $name=GenerationOptions::text($name,'folder name',100);if($name==='')throw new InvalidArgumentException('Folder name required');$db=Database::getInstance();return $db->transaction(function()use($db,$userId,$name){$pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn()||count(self::folders($userId))>=30)throw new InvalidArgumentException('Folder limit reached');$s=$pdo->prepare('INSERT INTO creative_folders(user_id,name,created_at,updated_at) VALUES(?,?,NOW(),NOW())');$s->execute([$userId,$name]);return (int)$pdo->lastInsertId();});
    }
    public static function update(int $userId,int $id,array $input): array
    {
        if(!GenerationLifecycle::lock($id))throw new InvalidArgumentException('Asset busy');
        try{return Database::getInstance()->transaction(function()use($userId,$id,$input){
            $row=PrivateMedia::owned($userId,$id,true);$pdo=Database::getInstance()->getConnection();$action=$input['action']??'organize';
            if($action==='trash'){
                if($row['status']==='generating')throw new InvalidArgumentException('Active asset cannot be deleted');
                $s=$pdo->prepare("SELECT COUNT(*) FROM social_post_targets t JOIN social_posts p ON p.id=t.social_post_id WHERE p.media_item_id=? AND t.status NOT IN ('published','failed','cancelled')");$s->execute([$id]);if((int)$s->fetchColumn())throw new InvalidArgumentException('Asset is used by a pending publication');
                // A pending edit or reference request must retain its source.
                $s=$pdo->prepare("SELECT generation_meta FROM media_items WHERE status='generating' AND user_id=?");$s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $meta){$m=json_decode((string)$meta,true);if((int)($m['options']['reference_id']??0)===$id||(int)($m['parent_id']??0)===$id||in_array($id,array_map('intval',(array)($m['parents']??[])),true))throw new InvalidArgumentException('Asset is used by an active generation');}
                $s=$pdo->prepare('UPDATE media_items SET publish_version=publish_version+CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END,deleted_at=COALESCE(deleted_at,NOW()),updated_at=NOW() WHERE id=? AND user_id=?');$s->execute([$id,$userId]);
            }elseif($action==='restore'){$s=$pdo->prepare('UPDATE media_items SET deleted_at=NULL,updated_at=NOW() WHERE id=? AND user_id=?');$s->execute([$id,$userId]);}
            elseif($action==='organize'){
                if(!empty($row['deleted_at']))throw new InvalidArgumentException('Restore asset first');
                $folder=$input['folder_id']??$row['folder_id']??0;$fav=$input['is_favorite']??(int)($row['is_favorite']??0);if(!is_scalar($folder)||!ctype_digit((string)$folder)||!in_array($fav,[0,1,'0','1',false,true],true))throw new InvalidArgumentException('Invalid organization');
                if((int)$folder){$s=$pdo->prepare('SELECT id FROM creative_folders WHERE id=? AND user_id=?');$s->execute([(int)$folder,$userId]);if(!$s->fetchColumn())throw new InvalidArgumentException('Invalid folder');}
                $s=$pdo->prepare('UPDATE media_items SET folder_id=?,is_favorite=?,updated_at=NOW() WHERE id=? AND user_id=?');$s->execute([(int)$folder?:null,(int)$fav,$id,$userId]);
            }else throw new InvalidArgumentException('Invalid library action');
            return GenerationOptions::publicItem(PrivateMedia::owned($userId,$id,true));
        });}finally{GenerationLifecycle::unlock($id);}
    }
}
