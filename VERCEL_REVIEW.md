# Vercel Review Build

This repository now includes a Vercel-friendly review build.

## What it is

- A static review site built from `vercel_review/`
- Output generated to `dist/`
- No PHP runtime required
- No MySQL required
- Demo data stored in browser `localStorage`

## Important routes

- `/` -> `/admin/login`
- `/admin/login`
- `/group`
- `/admin/index` -> `/group`
- `/admin/changePassword`
- `/link/:code`

## Build settings

- Framework Preset: `Other`
- Build Command: `node scripts/build-vercel.mjs`
- Output Directory: `dist`

## Notes

- This is for visual review and interaction review on Vercel.
- It does not execute the original ThinkPHP backend.
- The original PHP app remains in `secure_v2/`.
