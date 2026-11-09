import test from 'node:test';
import assert from 'node:assert/strict';
import type {
  DistributionTargets,
  Resource,
  ResourceDistributionRequest,
} from '@/lib/api';
import {
  accessForState,
  buildContributionItems,
  reviewerFeedback,
  selectOwnRequests,
  stateForRequestStatus,
} from '../resourceLifecycle.ts';
import {
  availableAudienceTypes,
  emptyAudienceSelection,
  formatAudience,
  isAudienceSelectionComplete,
  toDistributionSubmission,
} from '../audience.ts';
import { api } from '../../../lib/api.ts';

const OWNER_ID = 7;
const OTHER_ID = 9;

function makeResource(overrides: Partial<Resource> = {}): Resource {
  return {
    id: 1,
    uuid: '00000000-0000-0000-0000-000000000001',
    title: 'Community health handbook',
    description: 'A field guide for community health workers.',
    category_id: 3,
    category: { id: 3, label: 'Health' },
    language: 'en',
    intended_audience: 'Community health workers',
    publisher: null,
    publication_date: null,
    version_number: '1.0',
    status: 'draft',
    current_version_id: 11,
    currentVersion: undefined,
    thumbnail_storage_disk: null,
    thumbnail_storage_path: null,
    published_at: null,
    archived_at: null,
    published_by_user_id: null,
    created_by_user_id: OWNER_ID,
    updated_by_user_id: OWNER_ID,
    origin_assignment_id: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-02T00:00:00Z',
    ...overrides,
  } as Resource;
}

function makeRequest(
  overrides: Partial<ResourceDistributionRequest> = {}
): ResourceDistributionRequest {
  return {
    id: 100,
    resource_id: 1,
    requested_by_user_id: OWNER_ID,
    audience_type: 'campaign',
    audience_key: 'campaign:5',
    target_org_unit_id: null,
    target_campaign_id: 5,
    target_site_id: null,
    target_team_id: null,
    recipient_user_id: null,
    resource_version_id: 11,
    supersedes_request_id: null,
    status: 'pending_review',
    submitted_at: '2026-01-03T00:00:00Z',
    decided_at: null,
    decided_by_user_id: null,
    decision_reason: null,
    created_at: '2026-01-03T00:00:00Z',
    updated_at: '2026-01-03T00:00:00Z',
    resource: makeResource(),
    version: undefined,
    requester: { id: OWNER_ID, first_name: 'Amina', last_name: 'Okello' },
    decisions: [],
    ...overrides,
  } as ResourceDistributionRequest;
}

const TARGETS: DistributionTargets = {
  organizational_units: [
    {
      id: 1,
      name: 'Uganda Union Mission',
      parent_id: null,
      org_unit_type_id: 1,
      short_code: 'UUM',
      type: { id: 1, code: 'union', name: 'Union' },
    },
    {
      id: 2,
      name: 'Central Conference',
      parent_id: 1,
      org_unit_type_id: 2,
      short_code: 'CC',
      type: { id: 2, code: 'conference', name: 'Conference' },
    },
  ],
  campaigns: [{ id: 5, name: 'Hope Campaign', org_unit: 'Central Conference' }],
  sites: [{ id: 6, name: 'Kampala Site', campaign_id: 5 }],
  teams: [{ id: 8, name: 'Kampala Team', campaign_id: 5, site_id: 6 }],
  audience_types: [
    'union',
    'conference',
    'mission_field',
    'zone',
    'church_district',
    'local_church',
    'campaign',
    'site',
    'team',
    'private',
  ],
};

test('selectOwnRequests keeps only the signed-in contributor requests', () => {
  const requests = [
    makeRequest({ id: 1, requested_by_user_id: OWNER_ID }),
    makeRequest({ id: 2, requested_by_user_id: OTHER_ID }),
  ];

  const own = selectOwnRequests(requests, OWNER_ID);

  assert.deepEqual(
    own.map((request) => request.id),
    [1]
  );
});

test('buildContributionItems merges a draft with its newest own request', () => {
  const draft = makeResource();
  const older = makeRequest({
    id: 10,
    status: 'returned',
    submitted_at: '2026-01-03T00:00:00Z',
  });
  const newer = makeRequest({
    id: 11,
    status: 'pending_review',
    submitted_at: '2026-01-05T00:00:00Z',
  });

  const items = buildContributionItems([draft], [older, newer], OWNER_ID);

  assert.equal(items.length, 1);
  assert.equal(items[0].state, 'pending_review');
  assert.equal(items[0].request?.id, 11);
});

test('buildContributionItems never surfaces another contributor request', () => {
  const otherRequest = makeRequest({
    id: 20,
    requested_by_user_id: OTHER_ID,
    resource_id: 99,
    resource: makeResource({ id: 99, created_by_user_id: OTHER_ID }),
  });

  const items = buildContributionItems([], [otherRequest], OWNER_ID);

  assert.deepEqual(items, []);
});

test('returned requests expose editor and resubmission actions plus feedback', () => {
  const request = makeRequest({
    id: 30,
    status: 'returned',
    decision_reason: 'Please correct the dosage table before resubmitting.',
    decisions: [
      {
        id: 1,
        action: 'returned',
        reason: 'Please correct the dosage table before resubmitting.',
        actor_user_id: 2,
        actor: { id: 2, first_name: 'Grace', last_name: 'Namara' },
        created_at: '2026-01-04T00:00:00Z',
      },
    ],
  });

  const [item] = buildContributionItems([makeResource()], [request], OWNER_ID);
  const access = accessForState(item.state);

  assert.equal(item.state, 'changes_requested');
  assert.equal(access.canEdit, true);
  assert.equal(access.canResubmit, true);
  assert.equal(access.canSubmit, false);
  assert.equal(reviewerFeedback(item), request.decision_reason);
});

test('returned requests display refreshed draft metadata, not the submission snapshot', () => {
  const draft = makeResource({ title: 'Corrected title' });
  const request = makeRequest({
    id: 31,
    status: 'returned',
    resource: makeResource({ title: 'Old submission title' }),
  });

  const [item] = buildContributionItems([draft], [request], OWNER_ID);

  assert.equal(item.resource.title, 'Corrected title');
});

test('pending and approved requests expose no contributor mutation actions', () => {
  assert.deepEqual(accessForState('pending_review'), {
    canEdit: false,
    canSubmit: false,
    canResubmit: false,
  });
  assert.deepEqual(accessForState('approved'), {
    canEdit: false,
    canSubmit: false,
    canResubmit: false,
  });
  assert.equal(stateForRequestStatus('rejected'), 'rejected');
  assert.equal(stateForRequestStatus('returned'), 'changes_requested');
});

test('availableAudienceTypes excludes private and unsupported targets', () => {
  const types = availableAudienceTypes(TARGETS);

  assert.ok(types.includes('union'));
  assert.ok(types.includes('conference'));
  assert.ok(types.includes('campaign'));
  assert.ok(types.includes('site'));
  assert.ok(types.includes('team'));
  assert.equal(types.includes('private'), false);
  assert.equal(types.includes('mission_field'), false);
});

test('a fresh submission starts with no audience selected', () => {
  const selection = emptyAudienceSelection();

  assert.equal(selection.audience_type, '');
  assert.equal(isAudienceSelectionComplete(selection), false);
});

test('audience selection requires its own target and sends only that target', () => {
  assert.equal(isAudienceSelectionComplete({ audience_type: 'union' }), true);
  assert.equal(isAudienceSelectionComplete({ audience_type: 'campaign' }), false);
  assert.equal(
    isAudienceSelectionComplete({ audience_type: 'campaign', target_campaign_id: 5 }),
    true
  );

  const payload = toDistributionSubmission({
    audience_type: 'campaign',
    target_campaign_id: 5,
    target_site_id: 999,
    target_org_unit_id: 123,
  });

  assert.deepEqual(payload, { audience_type: 'campaign', target_campaign_id: 5 });
});

test('formatAudience describes organisational and private requests', () => {
  const conference = makeRequest({
    audience_type: 'conference',
    target_campaign_id: null,
    target_org_unit_id: 2,
  });
  assert.equal(formatAudience(conference, TARGETS), 'Conference · Central Conference');

  const privateRequest = makeRequest({
    audience_type: 'private',
    target_campaign_id: null,
    recipient_user_id: 42,
  });
  assert.equal(formatAudience(privateRequest, TARGETS), 'Private recipient · user #42');
});

async function captureRequest(
  call: () => Promise<unknown>
): Promise<RequestInit> {
  const originalFetch = globalThis.fetch;
  const calls: RequestInit[] = [];

  globalThis.fetch = (async (_input: RequestInfo | URL, init?: RequestInit) => {
    if (init) calls.push(init);
    return new Response(JSON.stringify({ id: 1 }), {
      status: 201,
      headers: { 'content-type': 'application/json' },
    });
  }) as typeof fetch;

  try {
    await call();
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(calls.length, 1);
  return calls[0];
}

test('the API client forwards FormData uploads instead of stringifying them', async () => {
  const formData = new FormData();
  formData.append('title', 'Draft title');
  formData.append('file', new Blob(['content'], { type: 'application/pdf' }), 'draft.pdf');

  const init = await captureRequest(() => api.post('/resources', formData));

  assert.equal(init.body, formData);
  assert.equal((init.headers as Record<string, string>)['Content-Type'], undefined);
});

test('the API client method-spoofs FormData PATCH requests', async () => {
  const formData = new FormData();
  formData.append('title', 'Updated title');

  const init = await captureRequest(() => api.patch('/resources/1', formData));

  assert.equal(init.method, 'POST');
  assert.equal((init.body as FormData).get('_method'), 'PATCH');
  assert.equal((init.body as FormData).get('title'), 'Updated title');
});
