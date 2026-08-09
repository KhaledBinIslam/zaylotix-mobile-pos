<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsappBulkLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'user_id', 'send_type', 'template_name', 'message', 'recipients_count', 'sent_count', 'failed_count'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
