# Proposal

## Why

A research project needs to measure how well people of different generations (Gen X / Millennial / Gen Z) distinguish AI-generated human faces (GAN and diffusion models) from real faces, using accuracy and response time. A university openhouse provides a large convenience sample; a mobile web game accessed via QR code turns that crowd into research data.

## What Changes

- Build a new mobile-first experiment website (jsPsych frontend, PHP backend, MySQL) deployed at an openhouse via QR code
- Two-round detection game: Round 1 is 2AFC (which of two faces is AI), Round 2 is single-image binary judgment (Is this AI? yes/no)
- All participants play both rounds in a fixed order; no gating, no pass thresholds
- Collect demographics (birth year, gender, AI familiarity) plus auto-detected device metadata
- Record per-trial response, correctness, and response time; save every trial to the backend immediately (dropout tolerance)
- Thai/English bilingual UI with language select on the landing page
- Accuracy-only final score with leaderboard shown under an anonymous alias; prize contact captured in a store separate from research data
- Post-game data-use consent request and debrief; no per-trial feedback during play
- Reference design: `design_v01_2026-10-29.md` (project root)

## Capabilities

### New Capabilities
- `experiment-flow`: participant journey from landing page through demographics, instructions, practice, Round 1 (2AFC), Round 2 (binary yes/no), final score, and post-game consent/debrief
- `data-capture`: anonymous session records and immediate per-trial persistence of responses, correctness, and response times
- `stimulus-management`: balanced real/GAN/diffusion face stimulus sets, randomized ordering, preloading, and identical preprocessing
- `leaderboard-prize`: accuracy-only scoring, alias-based leaderboard, and isolation of prize contact data from research data

### Modified Capabilities

(none — greenfield project, no existing specs)

## Impact

- **New system**: no existing code in scope yet; a PHP/jsPsych draft exists outside this repo and will be adapted during implementation (open item)
- **Dependencies**: jsPsych library, PHP server with HTTPS, MySQL, hosted stimulus images
- **Open constraints** (see design doc §8): exact GAN/diffusion models and real-face dataset source, hosting arrangement, exact trial counts, prize mechanics, IRB confirmation that post-game consent is covered
- **Ethics sensitivity**: identifiable face stimuli (PDPA), anonymous data storage, prize contact isolation
