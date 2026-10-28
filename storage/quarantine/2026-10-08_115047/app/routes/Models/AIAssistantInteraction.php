<?php
/**
 * Tourfecto - AI Assistant Interaction Model (Marketing Assistant)
 * @version 1.0.0
 */
class AIAssistantInteraction extends Model {
    protected $table = 'ai_assistant_interactions';
    protected $fillable = [
        'user_id', 'type', 'title', 'input_payload', 'output'
    ];
}
