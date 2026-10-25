<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WhatsAppConnectionRequest;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Store;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppLog;
use App\Models\WhatsAppMessageTemplate;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use App\Services\WhatsApp\WhatsAppTemplateVariables;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WhatsAppSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(WhatsAppProviderRegistry $providers): View
    {
        $this->authorize('settings.view');
        $user = request()->user();
        $storeIds = $user->is_super_admin ? null : $user->accessibleStoreIds();

        return view('admin.settings.whatsapp', [
            'connections' => WhatsAppConnection::query()->with('store:id,name')
                ->when($storeIds !== null, fn ($query) => $query->whereIn('store_id', $storeIds))
                ->orderByRaw('store_id IS NOT NULL')->orderBy('name')->get(),
            'providers' => $providers->all(),
            'stores' => Store::query()->active()->when($storeIds !== null, fn ($query) => $query->whereIn('id', $storeIds))->ordered()->get(['id', 'name']),
            'templates' => WhatsAppMessageTemplate::query()
                ->when($storeIds !== null, fn ($query) => $query->whereIn('store_id', $storeIds))
                ->orderBy('document_type')->orderBy('locale')->get(),
            'documentTypes' => ['sale_receipt', 'quotation', 'credit_note', 'customer_statement', 'customer_payment', 'supplier_payment'],
            'templateVariables' => WhatsAppTemplateVariables::all(),
        ]);
    }

    public function editConnection(WhatsAppConnection $connection, WhatsAppProviderRegistry $providers): View
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardConnection(request(), $connection);

        $user = request()->user();
        $storeIds = $user->is_super_admin ? null : $user->accessibleStoreIds();

        return view('admin.settings.whatsapp-connection', [
            'connection' => $connection->load('store:id,name'),
            'providers' => $providers->all(),
            'stores' => Store::query()->active()
                ->when($storeIds !== null, fn ($query) => $query->whereIn('id', $storeIds))
                ->ordered()->get(['id', 'name']),
        ]);
    }

    public function store(WhatsAppConnectionRequest $request, WhatsAppProviderRegistry $providers): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardDemo($request);
        $data = $request->connectionData();
        $providers->get($data['provider']);
        $this->guardStore($request, $data['store_id']);
        $this->guardOneConnectionPerScope($data['store_id']);

        try {
            $connection = WhatsAppConnection::query()->create($data + [
                'status' => WhatsAppConnection::STATUS_DISCONNECTED,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
        } catch (QueryException $e) {
            $this->translateScopeConstraint($e);
            throw $e;
        }

        return $this->jsonOrRedirect($request, __('whatsapp.flash.connection_saved'), route('admin.settings.whatsapp.connections.edit', $connection), ['data' => ['id' => $connection->id]]);
    }

    public function update(WhatsAppConnectionRequest $request, WhatsAppConnection $connection, WhatsAppProviderRegistry $providers): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardConnection($request, $connection);
        $this->guardDemo($request);
        $data = $request->connectionData($connection);
        $providers->get($data['provider']);
        $this->guardStore($request, $data['store_id']);
        $this->guardOneConnectionPerScope($data['store_id'], $connection->id);
        try {
            $connection->update($data + ['updated_by' => $request->user()->id]);
        } catch (QueryException $e) {
            $this->translateScopeConstraint($e);
            throw $e;
        }

        return $this->jsonOrRedirect($request, __('whatsapp.flash.connection_saved'), route('admin.settings.whatsapp.connections.edit', $connection));
    }

    public function test(Request $request, WhatsAppConnection $connection, WhatsAppProviderRegistry $providers): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardConnection($request, $connection);
        $this->guardDemo($request);
        $result = $providers->get($connection->provider)->testConnection($connection);
        $connection->forceFill([
            'status' => $result->successful ? WhatsAppConnection::STATUS_CONNECTED : WhatsAppConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_connected_at' => $result->successful ? now() : $connection->last_connected_at,
            'last_error' => $result->successful ? null : $result->message,
        ])->save();

        return $result->successful
            ? $this->jsonOrRedirect($request, $result->message, route('admin.settings.whatsapp.connections.edit', $connection))
            : $this->jsonOrError($request, $result->message, route('admin.settings.whatsapp.connections.edit', $connection));
    }

    public function sendTest(Request $request, WhatsAppConnection $connection): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardConnection($request, $connection);
        $this->guardDemo($request);
        abort_unless($connection->status === WhatsAppConnection::STATUS_CONNECTED, 422);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'template_name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'locale' => ['required', 'string', 'max:16'],
            'body' => ['nullable', 'string', 'max:4096'],
        ]);
        $store = $connection->store ?: current_store();
        $phone = PhoneNormalizer::toE164($data['phone'], $store?->country_code);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('whatsapp.errors.invalid_phone')]);
        }

        $log = WhatsAppLog::query()->create([
            'store_id' => $store?->id ?: current_store_id(),
            'whatsapp_connection_id' => $connection->id,
            'mode' => 'cloud_api',
            'recipient_phone' => $phone,
            'reference_type' => $connection->getMorphClass(),
            'reference_id' => $connection->id,
            'message_template' => $data['template_name'],
            'payload' => ['document_type' => 'test', 'body' => $data['body'] ?? '', 'locale' => $data['locale'], 'parameters' => [], 'template_name' => $data['template_name']],
            'status' => WhatsAppLog::STATUS_QUEUED,
            'created_by' => $request->user()->id,
        ]);
        SendWhatsAppMessage::dispatch($log->id)->afterCommit();

        return $this->jsonOrRedirect($request, __('whatsapp.flash.test_queued'), route('admin.settings.whatsapp.connections.edit', $connection));
    }

    public function destroy(Request $request, WhatsAppConnection $connection): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.credentials');
        $this->guardConnection($request, $connection);
        $this->guardDemo($request);
        WhatsAppLog::query()
            ->where('whatsapp_connection_id', $connection->id)
            ->where('status', WhatsAppLog::STATUS_QUEUED)
            ->update(['status' => WhatsAppLog::STATUS_FAILED, 'error_message' => __('whatsapp.errors.queued_connection_deleted')]);
        $connection->delete();

        return $this->jsonOrRedirect($request, __('whatsapp.flash.connection_deleted'), route('admin.settings.whatsapp.edit'));
    }

    public function saveTemplate(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.whatsapp.update');
        $this->guardDemo($request);
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'document_type' => ['required', 'in:sale_receipt,quotation,credit_note,customer_statement,customer_payment,supplier_payment'],
            'locale' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z_\-]+$/'],
            'body' => ['required', 'string', 'max:4096'],
        ]);
        $this->guardStore($request, isset($data['store_id']) ? (int) $data['store_id'] : null);
        $allowed = WhatsAppTemplateVariables::all();
        preg_match_all('/{{\s*([^}]+?)\s*}}/', $data['body'], $matches);
        $unknown = array_values(array_diff(array_unique(array_map('trim', $matches[1] ?? [])), $allowed[$data['document_type']]));
        if ($unknown) {
            throw ValidationException::withMessages(['body' => __('whatsapp.errors.unknown_variables', ['variables' => implode(', ', $unknown)])]);
        }

        WhatsAppMessageTemplate::query()->updateOrCreate(
            ['store_id' => $data['store_id'] ?? null, 'document_type' => $data['document_type'], 'locale' => $data['locale']],
            ['body' => $data['body'], 'is_default' => false],
        );

        return $this->jsonOrRedirect($request, __('whatsapp.flash.template_saved'), route('admin.settings.whatsapp.edit'));
    }

    private function guardStore(Request $request, ?int $storeId): void
    {
        if (! $request->user()->is_super_admin
            && ($storeId === null || ! in_array($storeId, $request->user()->accessibleStoreIds(), true))) {
            abort(403);
        }
    }

    private function guardConnection(Request $request, WhatsAppConnection $connection): void
    {
        $this->guardStore($request, $connection->store_id ? (int) $connection->store_id : null);
    }

    private function guardOneConnectionPerScope(?int $storeId, ?int $ignoreId = null): void
    {
        $query = WhatsAppConnection::query()->where('store_id', $storeId);
        if ($storeId === null) {
            $query->whereNull('store_id');
        }
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['store_id' => __('whatsapp.errors.connection_scope_exists')]);
        }
    }

    private function translateScopeConstraint(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'whatsapp_connections_live_scope_unique')
            || str_contains($message, 'whatsapp_connections.live_scope_key')) {
            throw ValidationException::withMessages([
                'store_id' => __('whatsapp.errors.connection_scope_exists'),
            ]);
        }
    }

    private function guardDemo(Request $request): void
    {
        if (pos_is_demo()) {
            throw ValidationException::withMessages(['_action' => __('settings.demo.locked')]);
        }
    }
}
