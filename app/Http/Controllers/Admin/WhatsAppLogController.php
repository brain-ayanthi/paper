<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('whatsapp.logs.view');
        $storeId = (int) current_store_id();
        $query = WhatsAppLog::query()->with(['connection:id,name,provider', 'creator:id,name'])->forStore($storeId)->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->string('mode'));
        }
        if ($request->filled('q')) {
            $needle = '%'.addcslashes((string) $request->string('q'), '%_\\').'%';
            $query->where(fn ($q) => $q->where('recipient_phone', 'like', $needle)->orWhere('message_template', 'like', $needle));
        }

        return view('admin.whatsapp.logs', ['logs' => $query->paginate(30)->withQueryString()]);
    }

    public function retry(WhatsAppLog $whatsappLog): RedirectResponse
    {
        $this->authorize('whatsapp.logs.retry');
        abort_unless((int) $whatsappLog->store_id === (int) current_store_id(), 404);
        abort_unless($whatsappLog->status === WhatsAppLog::STATUS_FAILED && $whatsappLog->whatsapp_connection_id, 422);
        $whatsappLog->forceFill(['status' => WhatsAppLog::STATUS_QUEUED, 'error_message' => null, 'next_retry_at' => null])->save();
        SendWhatsAppMessage::dispatch($whatsappLog->id)->afterCommit();

        return back()->with('success', __('whatsapp.flash.retry_queued'));
    }
}
