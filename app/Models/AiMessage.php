<?php

/** Tourfecto - AI Assistant Message Model @version 1.0.0 */
class AiMessage extends Model
{
    protected $table = 'ai_assistant_messages';
    protected $fillable = ['conversation_id', 'role', 'content'];
}
