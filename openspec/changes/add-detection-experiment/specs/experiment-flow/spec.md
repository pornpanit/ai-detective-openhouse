# Spec Delta

## Purpose

Defines the end-to-end participant journey of the openhouse detection game: from landing page through demographics, practice, two scored rounds, final score, and the post-game consent/debrief, in both Thai and English.

## ADDED Requirements

### Requirement: Bilingual landing page
The system SHALL present a landing page with a Thai/English language selection, and all subsequent screens SHALL render in the selected language for the entire session.

#### Scenario: Language selection persists
- **WHEN** a participant selects "English" on the landing page
- **THEN** every later screen (intro, demographics, instructions, rounds, end screens) displays in English without requiring re-selection

#### Scenario: Default before selection
- **WHEN** a participant opens the landing page and has not chosen a language
- **THEN** both language options are shown and progress is blocked until one is selected

### Requirement: Demographics collection
The system SHALL collect birth year and gender before the first scored round, SHALL derive the generation cohort (Gen X / Millennial / Gen Z) from birth year, and SHALL NOT ask familiarity questions before or during scored rounds.

#### Scenario: Demographics precede scored trials
- **WHEN** a participant completes the demographics form
- **THEN** the generation cohort is stored on the session record before Round 1 begins

#### Scenario: Birth year required
- **WHEN** the demographics form is submitted without a birth year
- **THEN** submission is rejected with a prompt in the selected language and the session does not proceed

#### Scenario: No pre-game familiarity priming
- **WHEN** the pre-game and scored-round screens are shown
- **THEN** no digital- or AI-familiarity question appears

### Requirement: Practice trials before scored rounds
The system SHALL present 2–3 practice trials using images that never appear in scored rounds, with no indication of correctness.

#### Scenario: Practice uses separate images
- **WHEN** a participant completes practice trials
- **THEN** none of the practice images appear in Round 1 or Round 2

#### Scenario: No practice feedback
- **WHEN** a participant answers a practice trial
- **THEN** the system displays no correct/incorrect signal and advances to the next trial

### Requirement: Round 1 two-alternative forced choice
Round 1 SHALL present two face images side by side and ask "which one is AI?"; each trial SHALL contain exactly one AI image and one real image, and a trial is scored correct when the AI image is selected.

#### Scenario: Correct selection
- **WHEN** a participant taps the AI-generated image in a Round 1 trial
- **THEN** the trial is recorded as correct and the next trial begins

#### Scenario: Incorrect selection
- **WHEN** a participant taps the real image in a Round 1 trial
- **THEN** the trial is recorded as incorrect with no correctness signal shown

### Requirement: Round 2 single-image binary judgment
Round 2 SHALL present one face image at a time with the question "Is this AI?" and exactly two response options (yes / no).

#### Scenario: Binary response only
- **WHEN** a Round 2 trial is displayed
- **THEN** only "yes" and "no" responses are accepted; no third option exists

### Requirement: Fixed round order without gating
The system SHALL present Round 1 (2AFC) before Round 2 (binary) for every participant, with no pass threshold, unlock condition, or round skipping.

#### Scenario: Everyone plays both rounds
- **WHEN** any participant finishes Round 1 regardless of accuracy
- **THEN** Round 2 begins with the full trial count

### Requirement: No per-trial feedback and no response deadline
During scored rounds the system SHALL NOT reveal correctness per trial, SHALL NOT show a running score, and SHALL wait indefinitely for each response.

#### Scenario: Slow response accepted
- **WHEN** a participant waits 30 seconds before responding to a trial
- **THEN** the response is accepted and its response time recorded without any timeout event

#### Scenario: No mid-game score
- **WHEN** a participant is between trials within a round
- **THEN** no score or accuracy information is displayed

### Requirement: Post-game data-use consent and debrief
After the final score screen the system SHALL ask whether the participant's anonymous data may be used for research, SHALL store that answer, SHALL then show the familiarity survey, and SHALL end with a debrief explaining how the data is used.

#### Scenario: Consent declined
- **WHEN** a participant answers "no" to the data-use request
- **THEN** the session record is marked not-consented and its trial data is excluded from research exports while the game experience completes normally

#### Scenario: Consent accepted
- **WHEN** a participant answers "yes"
- **THEN** the session record is marked consented and the familiarity survey is shown before the debrief

#### Scenario: Consent order at natural end
- **WHEN** the final round completes
- **THEN** screens appear in the order: final score, consent question, familiarity survey, debrief

### Requirement: End-of-game familiarity survey
After the consent question the system SHALL present a five-item digital/AI familiarity survey in the session language, shown regardless of the consent answer, with every item required before the debrief can proceed; answers SHALL be stored on the session record.

#### Scenario: Survey after consent
- **WHEN** a participant answers the consent question at natural game end
- **THEN** the familiarity survey appears next, before the debrief screen

#### Scenario: Survey answers required
- **WHEN** a participant taps Continue with one or more survey items unanswered
- **THEN** submission is blocked with a prompt and the session does not proceed to the debrief; answers are stored only once all five are completed (NULL only if the session is abandoned)

### Requirement: In-game quit option with consent capture
The system SHALL offer a quit control during all scored rounds; quitting SHALL ask for confirmation, then present the consent question and a short debrief, and already-saved trials SHALL remain stored with the session marked incomplete.

#### Scenario: Quit mid-round captures consent
- **WHEN** a participant taps quit and confirms during Round 1
- **THEN** the consent question is shown, saved trials remain stored, and the session is marked incomplete

#### Scenario: Accidental quit protection
- **WHEN** a participant taps the quit control
- **THEN** the system asks for confirmation before leaving the round

#### Scenario: Quit path skips survey
- **WHEN** a participant quits mid-round
- **THEN** the quit flow shows consent and a short debrief but not the five-item familiarity survey

### Requirement: Session duration bound
The system SHALL keep a typical uninterrupted session within approximately 7–8 minutes and SHALL NOT exceed 15 minutes for the configured trial counts (12 two-alternative + 24 single-image).

#### Scenario: Typical completion time
- **WHEN** a participant completes all screens without abnormal delay
- **THEN** total session duration is approximately 7–8 minutes

### Requirement: Detective narrative presentation
The system SHALL present the experiment under a detective theme in both languages — introductory briefing about a people database contaminated with AI fakes, rounds labeled as investigations, leaderboard titled "Top Detectives", and the final accuracy score presented as a "database cleaned" percentage — and the debrief SHALL disclose that the narrative is fictional framing and state the actual research purpose.

#### Scenario: Themed end score
- **WHEN** a participant completes the final scored round
- **THEN** the end screen presents the accuracy-only score as a "database cleaned" percentage in the selected language

#### Scenario: Debrief discloses the framing
- **WHEN** the debrief screen is shown
- **THEN** it states that the detective story was fictional framing and explains the actual research use of the data

#### Scenario: Theme changes no mechanics
- **WHEN** the themed flow runs against the other requirements in this spec
- **THEN** trial mechanics, feedback rules, scoring, and data capture behave identically to the unthemed requirements
