<?php

/** Tourfecto - AI Assistant Conversation Model @version 1.0.0 */
class AiConversation extends Model
{
    protected $table = 'ai_assistant_conversations';
    protected $fillable = ['user_id', 'title'];
}
