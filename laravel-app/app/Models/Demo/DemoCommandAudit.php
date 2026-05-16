<?php

namespace App\Models\Demo;

use Illuminate\Database\Eloquent\Model;

class DemoCommandAudit extends Model
{
    protected $table = 'demo_commands_audit';

    protected $fillable = [
        'tenant_id', 'source', 'command_text',
        'parsed_operations', 'status', 'error_msg', 'applied_at',
    ];

    protected $casts = [
        'parsed_operations' => 'array',
        'applied_at'        => 'datetime',
    ];

    public const SOURCE_NL          = 'nl_console';
    public const SOURCE_FORM        = 'canned_form';
    public const SOURCE_DIRECT_EDIT = 'direct_edit';
    public const SOURCE_SEED_RESET  = 'seed_reset';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
}
