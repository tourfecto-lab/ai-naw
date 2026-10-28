-- Run in phpMyAdmin on the existing Tourfecto database.
-- New image requests only. Existing requests, balances and other services are unchanged.
START TRANSACTION;
SELECT revision, config_json, updated_by INTO @tf_revision_before, @tf_config_before, @tf_routing_actor
FROM ai_service_routing WHERE id=1 FOR UPDATE;
SET @tf_routing_actor = COALESCE(@tf_routing_actor,(SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1));
SET @tf_image_route = '{"enabled":true,"chain":["openai","gemini"],"models":{"openai":"gpt-image-1","gemini":"gemini-2.5-flash-image"}}';
UPDATE ai_service_routing
SET config_json=JSON_SET(config_json,'$.creative_studio_image',JSON_EXTRACT(@tf_image_route,'$')),
    revision=revision+1, updated_by=@tf_routing_actor, updated_at=NOW()
WHERE id=1 AND @tf_routing_actor IS NOT NULL AND JSON_VALID(config_json)
AND NOT (JSON_EXTRACT(config_json,'$.creative_studio_image') <=> JSON_EXTRACT(@tf_image_route,'$'));
SET @tf_routing_changed = ROW_COUNT();
INSERT INTO ai_service_routing_audit(revision,user_id,changes_json,created_at)
SELECT revision,@tf_routing_actor,JSON_OBJECT('creative_studio_image',JSON_OBJECT('before',JSON_EXTRACT(@tf_config_before,'$.creative_studio_image'),'after',JSON_EXTRACT(@tf_image_route,'$'))),NOW()
FROM ai_service_routing WHERE id=1 AND @tf_routing_changed=1;
COMMIT;
SELECT revision,JSON_EXTRACT(config_json,'$.creative_studio_image') AS image_route FROM ai_service_routing WHERE id=1;
