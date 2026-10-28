<?php

/** Tourfecto - Feature Flag Model @version 1.0.0 */
class FeatureFlag extends Model
{
    protected $table = 'feature_flags';
    protected $fillable = ['feature_key', 'label', 'is_enabled'];
}
