{{-- "Export payments as Excel" with an optional CS checklist — the same pop-up as the orders
     export. Needs $tripId and $csAgents. Keep it OUTSIDE any other <form>.

     Payment Log sheet: the payments made on orders handled by a ticked CS.
     Outstanding Balances sheet: customers with at least one order handled by a ticked CS,
     with their balance worked out over ALL their orders in the trip (so it stays correct).
     Nothing ticked = everything. --}}
<div class="modal fade" id="exportPaymentsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="GET" action="{{ route('payments.export') }}"
                  onsubmit="if (typeof showExport === 'function') showExport('Preparing payment export…')">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Payments as Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="trip_id" value="{{ $tripId }}">
                    <label class="form-label fw-semibold">Filter by CS (optional)</label>
                    <div class="form-text text-muted mb-2">
                        Leave everything unchecked to export everything. With a CS ticked, the Payment Log lists the
                        payments on orders that CS handled, and Outstanding Balances lists the customers who have at
                        least one of their orders (their balance still counts all of their orders in the trip).
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-link p-0 me-3" onclick="document.querySelectorAll('.payment-export-cs-check').forEach(c=>c.checked=true)">Select all</button>
                        <button type="button" class="btn btn-sm btn-link p-0" onclick="document.querySelectorAll('.payment-export-cs-check').forEach(c=>c.checked=false)">Clear</button>
                    </div>
                    <div style="max-height:260px;overflow-y:auto;" class="border rounded p-2">
                        @foreach($csAgents as $agent)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input payment-export-cs-check" name="cs_agent_ids[]" value="{{ $agent->id }}" id="payExportCs{{ $agent->id }}">
                            <label class="form-check-label" for="payExportCs{{ $agent->id }}">
                                {{ $agent->name }}@unless($agent->is_active) <span class="text-muted small">(inactive)</span>@endunless
                            </label>
                        </div>
                        @endforeach
                        <div class="form-check border-top mt-2 pt-2">
                            <input type="checkbox" class="form-check-input payment-export-cs-check" name="cs_agent_ids[]" value="none" id="payExportCsNone">
                            <label class="form-check-label text-muted" for="payExportCsNone">No CS agent assigned</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
