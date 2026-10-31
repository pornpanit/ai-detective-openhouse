# Spec Delta

## Purpose

Defines how the experiment records anonymous sessions and persists every trial's response, correctness, and response time immediately, so research data survives dropouts and supports analysis by image type and generation cohort.

## ADDED Requirements

### Requirement: Anonymous session record
The system SHALL create one session record per playthrough keyed by a random session identifier, containing no directly identifying information (no name, student ID, or contact details). Familiarity survey answers SHALL be nullable fields stored after game end; the consent answer SHALL be settable from both the natural end and the quit path; completion SHALL be flagged separately from consent.

#### Scenario: Session creation
- **WHEN** a participant starts a new playthrough
- **THEN** a session record with a random identifier is created and later stores demographics, language, device metadata, and completion status

#### Scenario: Quitter session retains consent
- **WHEN** a participant quits mid-round and answers the consent question
- **THEN** the session stores the consent answer, retains all saved trials, and is flagged incomplete

### Requirement: Immediate per-trial persistence
The system SHALL save each trial's data to the backend at the moment the trial completes, without waiting for the round or session to end.

#### Scenario: Mid-session abandonment
- **WHEN** a participant quits after completing 15 trials
- **THEN** all 15 completed trials are already stored and retrievable for analysis

#### Scenario: Trial save failure
- **WHEN** a save request fails due to a network error
- **THEN** the game continues and the client retries saving the trial without duplicating it

### Requirement: Trial record contents
For every scored trial the system SHALL record: session identifier, round number, trial index within round, image identifier, image type (real / GAN / diffusion), the participant's response, correctness, response time measured from stimulus onset to response, and a timestamp.

#### Scenario: Response time measurement
- **WHEN** a face image is displayed and the participant taps a response 2,340 ms later
- **THEN** the trial record stores RT of 2,340 ms measured from image display, excluding image loading time

### Requirement: Device metadata capture
The system SHALL automatically record device type, browser, and screen size on the session record as covariates, without asking the participant.

#### Scenario: Device recorded
- **WHEN** a participant plays on an Android phone with a 6.1-inch screen
- **THEN** the session record includes detectable device, browser, and screen information

### Requirement: Consent status on session
The system SHALL store the post-game data-use answer on the session record so consented and non-consented data can be separated in any export.

#### Scenario: Export filtering
- **WHEN** research data is exported
- **THEN** only sessions marked consented are included by default

#### Scenario: Consented quitters included
- **WHEN** a consented session ended early via the quit path
- **THEN** its saved trials are included in the default research export, with completion status available as a covariate or filter

### Requirement: Idempotent trial saves
The system SHALL prevent duplicate storage of the same trial within a session, including on retry after a network failure.

#### Scenario: Retry does not duplicate
- **WHEN** the client retries a trial save that already succeeded
- **THEN** the backend stores the trial exactly once
