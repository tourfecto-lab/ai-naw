<?php

/** Tourfecto - User Feature Override Model @version 1.0.0 */
class UserFeatureOverride extends Model
{
    protected $table = 'user_feature_overrides';
    protected $fillable = ['user_id', 'feature_key', 'is_enabled', 'note'];
}
