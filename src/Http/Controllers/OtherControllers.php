<?php

namespace ReachHub\Http\Controllers;

use ReachHub\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name',  'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%')
                  ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('subscribed')) {
            $query->where('subscribed', filter_var($request->subscribed, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(['success' => true, 'data' => $query->paginate($request->get('per_page', 20))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'nullable|email|unique:ck_contacts,email',
            'phone'         => 'nullable|string|max:20',
            'whatsapp'      => 'nullable|string|max:20',
            'fcm_token'     => 'nullable|string',
            'tags'          => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'subscribed'    => 'boolean',
        ]);

        $contact = Contact::create(array_merge(['subscribed' => true], $validated));

        return response()->json(['success' => true, 'data' => $contact], 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        $contact->load('lists');
        return response()->json(['success' => true, 'data' => $contact]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'email'         => "sometimes|email|unique:ck_contacts,email,{$contact->id}",
            'phone'         => 'sometimes|string|max:20',
            'whatsapp'      => 'sometimes|string|max:20',
            'fcm_token'     => 'sometimes|string',
            'tags'          => 'sometimes|array',
            'custom_fields' => 'sometimes|array',
            'subscribed'    => 'sometimes|boolean',
        ]);

        $contact->update($validated);

        return response()->json(['success' => true, 'data' => $contact]);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();
        return response()->json(['success' => true, 'message' => 'Contact deleted.']);
    }

    /**
     * Bulk import contacts via JSON array.
     * POST /contacts/import
     * Body: { "contacts": [...], "list_id": 1 (optional) }
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'contacts'          => 'required|array|min:1|max:5000',
            'contacts.*.name'   => 'required|string|max:255',
            'contacts.*.email'  => 'nullable|email',
            'contacts.*.phone'  => 'nullable|string|max:20',
            'list_id'           => 'nullable|exists:ck_contact_lists,id',
        ]);

        $created = 0;
        $skipped = 0;

        foreach ($request->contacts as $row) {
            $existing = null;

            if (!empty($row['email'])) {
                $existing = Contact::where('email', $row['email'])->first();
            } elseif (!empty($row['phone'])) {
                $existing = Contact::where('phone', $row['phone'])->first();
            }

            if ($existing) {
                $skipped++;
                $contact = $existing;
            } else {
                $contact = Contact::create(array_merge(['subscribed' => true], $row));
                $created++;
            }

            if ($request->filled('list_id')) {
                $contact->lists()->syncWithoutDetaching([$request->list_id]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => ['created' => $created, 'skipped' => $skipped],
        ]);
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Controllers;

use ReachHub\Models\ContactList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ContactListController extends Controller
{
    public function index(): JsonResponse
    {
        $lists = ContactList::withCount('contacts')->orderByDesc('created_at')->paginate(20);
        return response()->json(['success' => true, 'data' => $lists]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'tags'        => 'nullable|array',
        ]);

        $list = ContactList::create($validated);
        return response()->json(['success' => true, 'data' => $list], 201);
    }

    public function show(ContactList $list): JsonResponse
    {
        $list->loadCount('contacts');
        return response()->json(['success' => true, 'data' => $list]);
    }

    public function update(Request $request, ContactList $list): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'tags'        => 'nullable|array',
        ]);

        $list->update($validated);
        return response()->json(['success' => true, 'data' => $list]);
    }

    public function destroy(ContactList $list): JsonResponse
    {
        $list->delete();
        return response()->json(['success' => true, 'message' => 'List deleted.']);
    }

    // POST /lists/{list}/contacts/attach
    public function attach(Request $request, ContactList $list): JsonResponse
    {
        $request->validate(['contact_ids' => 'required|array', 'contact_ids.*' => 'integer']);
        $list->contacts()->syncWithoutDetaching($request->contact_ids);
        return response()->json(['success' => true, 'message' => count($request->contact_ids) . ' contact(s) attached.']);
    }

    // POST /lists/{list}/contacts/detach
    public function detach(Request $request, ContactList $list): JsonResponse
    {
        $request->validate(['contact_ids' => 'required|array', 'contact_ids.*' => 'integer']);
        $list->contacts()->detach($request->contact_ids);
        return response()->json(['success' => true, 'message' => count($request->contact_ids) . ' contact(s) detached.']);
    }
}

    // NOTE: Add to ContactController class
    // POST /contacts/{contact}/unsubscribe
    public function unsubscribe(Contact $contact): JsonResponse
    {
        $contact->update(['subscribed' => false, 'unsubscribed_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Contact unsubscribed (added to suppression list).']);
    }
