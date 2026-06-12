#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const API_URL = process.env.ARKGRID_PYLON_PDF_API_URL || 'https://arkgrid.example.com/outputs/api/pylon_pdf.php';
const AGENT_TOKEN = process.env.ARKGRID_AGENT_TOKEN;
const AGENT_NAME = process.env.ARKGRID_AGENT_NAME || 'openclaw';
const ARCHIVE_DIR = process.env.PYLON_PDF_ARCHIVE_DIR || path.resolve(process.cwd(), 'pylon-pdf-archive');

if (!AGENT_TOKEN) {
  throw new Error('ARKGRID_AGENT_TOKEN is required');
}

async function api(action, body = {}) {
  const response = await fetch(API_URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${AGENT_TOKEN}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ action, agent: AGENT_NAME, ...body }),
  });

  const payload = await response.json();
  if (!response.ok || payload.ok !== true) {
    throw new Error(`${action} failed: ${JSON.stringify(payload)}`);
  }
  return payload;
}

function sha256(filePath) {
  const hash = crypto.createHash('sha256');
  hash.update(fs.readFileSync(filePath));
  return hash.digest('hex');
}

async function run() {
  const { job } = await api('claim');
  if (!job) {
    return;
  }

  try {
    /*
     * OpenClaw/Playwright implementation placeholder:
     * - Open job.pylon_url in the approved persistent browser profile.
     * - If login, 2FA, CAPTCHA, or SSO is shown, do not automate around it.
     *   Call manual-login-required and stop.
     * - Do not click controls that approve proposals, edit customer pricing, or delete documents.
     * - Download the proposal PDF only.
     */
    fs.mkdirSync(ARCHIVE_DIR, { recursive: true });

    const downloadedPdfPath = process.env.PYLON_DOWNLOADED_PDF_PATH;
    if (!downloadedPdfPath) {
      await api('manual-login-required', {
        job_id: job.id,
        reason: 'Template requires OpenClaw download implementation or PYLON_DOWNLOADED_PDF_PATH for dry run.',
      });
      return;
    }

    const fileName = path.basename(downloadedPdfPath);
    const storagePath = path.join(ARCHIVE_DIR, `${job.id}-${fileName}`);
    fs.copyFileSync(downloadedPdfPath, storagePath);
    const stat = fs.statSync(storagePath);

    await api('complete', {
      job_id: job.id,
      file_name: fileName,
      storage_path: storagePath,
      sha256_hash: sha256(storagePath),
      file_size_bytes: stat.size,
      mime_type: 'application/pdf',
    });
  } catch (error) {
    await api('fail', {
      job_id: job.id,
      error: error instanceof Error ? error.message : String(error),
    });
  }
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
