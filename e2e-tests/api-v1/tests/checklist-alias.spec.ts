import fs from "node:fs";
import path from "node:path";
import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPatchJson,
  apiPostJson,
  expectApiSuccess,
  parseJson,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type Batch = { id: number; project_id: number; title: string };
type Item = { id: number; batch_id: number; status: string };
type Attachment = { id: number };

let api: APIRequestContext;
let pm: RoleSession;

let batchId = 0;
let itemId = 0;

const fixturePath = path.resolve(__dirname, "fixtures", "sample.png");

async function uploadItemAttachment(token: string, targetItemId: number): Promise<number> {
  const uploadRes = await api.post(`${cfg.apiBasePath}/checklist/item_attachments`, {
    multipart: {
      item_id: String(targetItemId),
      "attachments[]": {
        name: "sample.png",
        mimeType: "image/png",
        buffer: fs.readFileSync(fixturePath),
      },
    },
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });
  const body = await parseJson<ApiEnvelope<{ uploaded_count: number; attachments: Attachment[] }>>(uploadRes);
  expect(uploadRes.status()).toBe(200);
  expectApiSuccess(body);
  expect(body.data.uploaded_count).toBeGreaterThan(0);
  return body.data.attachments[0]?.id ?? 0;
}

async function uploadBatchAttachment(token: string, targetBatchId: number): Promise<number> {
  const uploadRes = await api.post(`${cfg.apiBasePath}/checklist/batch_attachments`, {
    multipart: {
      batch_id: String(targetBatchId),
      "attachments[]": {
        name: "sample.png",
        mimeType: "image/png",
        buffer: fs.readFileSync(fixturePath),
      },
    },
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });
  const body = await parseJson<ApiEnvelope<{ uploaded_count: number; attachments: Attachment[] }>>(uploadRes);
  expect(uploadRes.status()).toBe(200);
  expectApiSuccess(body);
  expect(body.data.uploaded_count).toBeGreaterThan(0);
  return body.data.attachments[0]?.id ?? 0;
}

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  pm = await loginRole(api, "pm");
});

test.afterAll(async () => {
  if (itemId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
      api,
      `${cfg.apiBasePath}/checklist/item?id=${itemId}`,
      undefined,
      authHeaders(pm)
    );
  }
  if (batchId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
      api,
      `${cfg.apiBasePath}/checklist/batch?id=${batchId}`,
      undefined,
      authHeaders(pm)
    );
  }
  await api.dispose();
});

test("checklist aliases map to full CRUD surface", async () => {
  const marker = Date.now();

  const createdBatch = await apiPostJson<ApiEnvelope<{ batch: Batch }>>(
    api,
    `${cfg.apiBasePath}/checklist/batches`,
    {
      project_id: cfg.projectId,
      title: `API V1 Alias Batch ${marker}`,
      module_name: "API V1",
      submodule_name: "Checklist",
      status: "open",
      assigned_qa_lead_id: cfg.accounts.qaLead.userId,
      notes: "created from alias spec",
    },
    authHeaders(pm)
  );
  expect(createdBatch.res.status()).toBe(201);
  expectApiSuccess(createdBatch.body);
  batchId = createdBatch.body.data.batch.id;

  const listBatch = await apiGet<ApiEnvelope<{ batches: Batch[] }>>(
    api,
    `${cfg.apiBasePath}/checklist/batches?project_id=${cfg.projectId}`,
    authHeaders(pm)
  );
  expect(listBatch.res.status()).toBe(200);
  expectApiSuccess(listBatch.body);
  expect(listBatch.body.data.batches.some((row) => row.id === batchId)).toBeTruthy();

  const getBatchByQuery = await apiGet<ApiEnvelope<{ batch: Batch }>>(
    api,
    `${cfg.apiBasePath}/checklist/batch?id=${batchId}`,
    authHeaders(pm)
  );
  expect(getBatchByQuery.res.status()).toBe(200);
  expectApiSuccess(getBatchByQuery.body);

  const getBatchByPath = await apiGet<ApiEnvelope<{ batch: Batch }>>(
    api,
    `${cfg.apiBasePath}/checklist/batches/${batchId}`,
    authHeaders(pm)
  );
  expect(getBatchByPath.res.status()).toBe(200);
  expectApiSuccess(getBatchByPath.body);

  const createdItem = await apiPostJson<ApiEnvelope<{ item: Item }>>(
    api,
    `${cfg.apiBasePath}/checklist/items`,
    {
      batch_id: batchId,
      sequence_no: 1,
      title: "Alias item",
      module_name: "API V1",
      submodule_name: "Checklist",
      description: "Created by alias suite",
      required_role: "QA Tester",
      priority: "medium",
      assigned_to_user_id: cfg.accounts.qaTester.userId,
    },
    authHeaders(pm)
  );
  expect(createdItem.res.status()).toBe(201);
  expectApiSuccess(createdItem.body);
  itemId = createdItem.body.data.item.id;

  const getItemByQuery = await apiGet<ApiEnvelope<{ item: Item }>>(
    api,
    `${cfg.apiBasePath}/checklist/item?id=${itemId}`,
    authHeaders(pm)
  );
  expect(getItemByQuery.res.status()).toBe(200);
  expectApiSuccess(getItemByQuery.body);

  const getItemByPath = await apiGet<ApiEnvelope<{ item: Item }>>(
    api,
    `${cfg.apiBasePath}/checklist/items/${itemId}`,
    authHeaders(pm)
  );
  expect(getItemByPath.res.status()).toBe(200);
  expectApiSuccess(getItemByPath.body);

  const patchItem = await apiPatchJson<ApiEnvelope<{ item: Item }>>(
    api,
    `${cfg.apiBasePath}/checklist/item?id=${itemId}`,
    {
      sequence_no: 2,
      title: "Alias item updated",
      module_name: "API V1",
      submodule_name: "Checklist",
      description: "Updated",
      required_role: "QA Tester",
      priority: "high",
      assigned_to_user_id: cfg.accounts.qaTester.userId,
    },
    authHeaders(pm)
  );
  expect(patchItem.res.status()).toBe(200);
  expectApiSuccess(patchItem.body);

  const status = await apiPostJson<ApiEnvelope<{ item: Item }>>(
    api,
    `${cfg.apiBasePath}/checklist/item_status`,
    {
      item_id: itemId,
      status: "in_progress",
    },
    authHeaders(pm)
  );
  expect(status.res.status()).toBe(200);
  expectApiSuccess(status.body);

  const itemAttachmentA = await uploadItemAttachment(pm.accessToken, itemId);
  expect(itemAttachmentA).toBeGreaterThan(0);

  const deleteItemAttachmentQuery = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/item_attachment?id=${itemAttachmentA}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteItemAttachmentQuery.res.status()).toBe(200);
  expectApiSuccess(deleteItemAttachmentQuery.body);

  const itemAttachmentB = await uploadItemAttachment(pm.accessToken, itemId);
  expect(itemAttachmentB).toBeGreaterThan(0);

  const deleteItemAttachmentPath = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/item-attachments/${itemAttachmentB}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteItemAttachmentPath.res.status()).toBe(200);
  expectApiSuccess(deleteItemAttachmentPath.body);

  const batchAttachmentA = await uploadBatchAttachment(pm.accessToken, batchId);
  expect(batchAttachmentA).toBeGreaterThan(0);

  const deleteBatchAttachmentQuery = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/batch_attachment?id=${batchAttachmentA}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteBatchAttachmentQuery.res.status()).toBe(200);
  expectApiSuccess(deleteBatchAttachmentQuery.body);

  const batchAttachmentB = await uploadBatchAttachment(pm.accessToken, batchId);
  expect(batchAttachmentB).toBeGreaterThan(0);

  const deleteBatchAttachmentPath = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/batch-attachments/${batchAttachmentB}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteBatchAttachmentPath.res.status()).toBe(200);
  expectApiSuccess(deleteBatchAttachmentPath.body);

  const deleteItem = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/item?id=${itemId}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteItem.res.status()).toBe(200);
  expectApiSuccess(deleteItem.body);
  itemId = 0;

  const deleteBatch = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
    api,
    `${cfg.apiBasePath}/checklist/batch?id=${batchId}`,
    undefined,
    authHeaders(pm)
  );
  expect(deleteBatch.res.status()).toBe(200);
  expectApiSuccess(deleteBatch.body);
  batchId = 0;
});
