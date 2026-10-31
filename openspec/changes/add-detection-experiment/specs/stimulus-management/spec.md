# Spec Delta

## Purpose

Defines how the real, GAN, and diffusion face stimulus sets are prepared, composed into rounds, ordered per session, and preloaded, so that image quality cues cannot leak the answer and timing data stays valid.

## ADDED Requirements

### Requirement: Three balanced stimulus sets
The system SHALL maintain three face-image sets — real, GAN-generated, and diffusion-generated — balanced across the sets on age, gender, and ethnicity of the depicted faces, with counts adequate for the configured trial totals.

#### Scenario: Set balance check
- **WHEN** stimulus sets are prepared for deployment
- **THEN** the demographic distribution (age, gender, ethnicity) is matched across the three sets within documented tolerance

### Requirement: Identical preprocessing across sets
All images across the three sets SHALL receive identical cropping, resolution, and compression so that no set is distinguishable by image-quality cues.

#### Scenario: Quality cue removed
- **WHEN** a participant compares a real and an AI image within a trial
- **THEN** resolution, crop style, and compression artifacts are indistinguishable between the two

### Requirement: Randomized order per session
The system SHALL randomize the image order and the left/right position (Round 1) independently for each session.

#### Scenario: Two sessions differ
- **WHEN** two participants play the same round
- **THEN** their image sequences and 2AFC image positions are independently randomized

### Requirement: Preloading before scored trials
The system SHALL fully download all scored-round images before Round 1 begins, and SHALL NOT count loading time as response time.

#### Scenario: No mid-round loading
- **WHEN** any scored trial is in progress
- **THEN** the trial image is already fully loaded in memory before it is displayed

#### Scenario: Preload progress indication
- **WHEN** images are still downloading before Round 1
- **THEN** the participant sees a loading indicator and no scored trial starts

### Requirement: Practice image separation
Images used in practice trials SHALL never appear in any scored round for that deployment.

#### Scenario: No practice leakage
- **WHEN** the round images for a session are selected
- **THEN** none of them were used in that session's practice trials

### Requirement: Round composition and six-cell balance
Round 1 SHALL consist of 12 two-alternative trials, each pairing exactly one AI image with one real image; Round 2 SHALL consist of 24 single-image trials. Each round SHALL draw equally per gender from the six cells formed by image source (real / GAN / diffusion) and face gender (male / female): each round uses 6 Real-M, 6 Real-F, 3 GAN-M, 3 GAN-F, 3 Diff-M, 3 Diff-F — giving session totals of 24 AI (12 GAN + 12 diffusion) and 24 real images, equal in number.

#### Scenario: Round 1 pairing
- **WHEN** a Round 1 trial is constructed
- **THEN** it contains exactly one AI image and one real image

#### Scenario: Six-cell session balance
- **WHEN** a session's trial plan is generated
- **THEN** every source × gender cell receives its specified equal allocation in each round, and total AI images equal total real images

### Requirement: Same-gender pairing in Round 1
Every Round 1 pair SHALL consist of an AI image and a real image depicting faces of the same gender, so gender cannot serve as a detection cue within a trial.

#### Scenario: Pair gender match
- **WHEN** any Round 1 trial's two images are compared
- **THEN** both depict faces of the same gender

### Requirement: Deployment image pool
The game SHALL operate from a curated deployment pool (2,000–5,000 images) drawn from the full 420,000-image dataset after batch processing, balanced across the six source × gender cells, with each image sized ~80–150KB.

#### Scenario: Pool fits deployment budget
- **WHEN** the deployment pool is exported
- **THEN** total pool size stays within the web host's storage budget and every cell has equal representation

### Requirement: Image reuse policy (unique-preferred)
The system SHALL prefer assigning never-used images to new sessions (per cell), and SHALL fall back to the rolling-window rule (no reuse within the previous 30 minutes) only when a cell's fresh supply is exhausted. Early players therefore see all-new faces ("half-day unique") with no risk of pool exhaustion.

#### Scenario: Fresh image preferred
- **WHEN** a new session draws from a cell that still has never-used images
- **THEN** only never-used images are eligible for that draw

#### Scenario: Silent fallback
- **WHEN** a cell's never-used supply is exhausted
- **THEN** the draw falls back to images unused for at least 30 minutes, and the player experience is unaffected

#### Scenario: Near-simultaneous sessions still share nothing
- **WHEN** two sessions are created within minutes of each other
- **THEN** their plans contain zero images in common, whether drawn from fresh supply or the window
