{{-- "Download all invoices (PDF)" for one trip, with an optional CS checklist — the same
     pop-up as the Excel export. Needs $tripId and $csAgents.

     A customer gets an invoice if at least ONE of their orders in the trip was handled by a
     ticked CS. Their invoice still lists ALL their orders (it is one combined invoice, so its
     total has to match what they really owe). Nothing ticked = every customer. --}}
<div class="modal fade" id="invoicePdfModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="GET" action="{{ route('trips.invoices.pdf', $tripId) }}"
                  onsubmit="if (typeof showExport === 'function') showExport('Building the invoices PDF — a big trip can take a minute…')">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Download Invoices (PDF)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Filter by CS (optional)</label>
                    <div class="form-text text-muted mb-2">
                        Leave everything unchecked to download every customer. A customer is included when at least one
                        of their orders was handled by a ticked CS &mdash; their invoice still shows all of their orders,
                        so the total stays correct.
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-link p-0 me-3" onclick="document.querySelectorAll('.invoice-cs-check').forEach(c=>c.checked=true)">Select all</button>
                        <button type="button" class="btn btn-sm btn-link p-0" onclick="document.querySelectorAll('.invoice-cs-check').forEach(c=>c.checked=false)">Clear</button>
                    </div>
                    <div style="max-height:260px;overflow-y:auto;" class="border rounded p-2">
                        @foreach($csAgents as $agent)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input invoice-cs-check" name="cs_agent_ids[]" value="{{ $agent->id }}" id="invCs{{ $agent->id }}">
                            <label class="form-check-label" for="invCs{{ $agent->id }}">
                                {{ $agent->name }}@unless($agent->is_active) <span class="text-muted small">(inactive)</span>@endunless
                            </label>
                        </div>
                        @endforeach
                        <div class="form-check border-top mt-2 pt-2">
                            <input type="checkbox" class="form-check-input invoice-cs-check" name="cs_agent_ids[]" value="none" id="invCsNone">
                            <label class="form-check-label text-muted" for="invCsNone">No CS agent assigned</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Download
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
