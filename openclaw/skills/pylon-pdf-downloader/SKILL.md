# Pylon Proposal PDF Downloader

Use this skill when OpenClaw is assigned ArkGrid Pylon proposal PDF download jobs.

## Boundaries

- Authenticate every ArkGrid API call with `Authorization: Bearer <ARKGRID_AGENT_TOKEN>`.
- Do not store, print, or transmit Pylon passwords.
- Do not bypass login, 2FA, CAPTCHA, SSO, or access controls.
- First Pylon login must be completed by a human in the browser profile used by OpenClaw.
- OpenClaw must not approve quotes, change customer pricing, or delete documents.
- OpenClaw only downloads proposal PDFs and reports archive metadata back to ArkGrid.

## Workflow

1. Call `outputs/api/pylon_pdf.php?action=claim` with the ArkGrid bearer token.
2. If the response has `"job": null`, stop.
3. Open the returned `pylon_url` in the approved browser profile.
4. If Pylon asks for login, 2FA, CAPTCHA, or SSO, stop and call `manual-login-required`.
5. Download the proposal PDF without approving, editing pricing, or deleting anything.
6. Archive the PDF to the configured local/archive path.
7. Call `complete` with `job_id`, `file_name`, `storage_path`, optional `sha256_hash`, `file_size_bytes`, and optional Pylon document id.
8. If the download fails for a non-login reason, call `fail` with the error message.

## API Actions

- `list-pending`: inspect available jobs.
- `claim`: atomically reserve one available job.
- `complete`: mark a claimed job complete and create a `pylon_documents` record.
- `fail`: record a retryable download failure.
- `manual-login-required`: pause a job until a human completes login.
- `list-documents`: inspect archived document metadata.

## Script Template

Use `scripts/download_pylon_pdf.template.js` as the starting point for a local OpenClaw/Playwright runner. Keep credentials out of the script and pass `ARKGRID_AGENT_TOKEN` through the environment.
