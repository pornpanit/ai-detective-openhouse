# Spec Delta

## Purpose

Defines accuracy-based scoring, the alias-only public leaderboard, and the strict isolation of prize contact data from research data, so a competitive openhouse game never compromises participant anonymity.

## ADDED Requirements

### Requirement: Accuracy-only final score
The final score SHALL be computed solely from correct and incorrect responses across scored rounds, with no component derived from response speed.

#### Scenario: Speed does not affect score
- **WHEN** two participants answer identically but one responds twice as fast
- **THEN** both receive the same score

### Requirement: Score revealed only at session end
The system SHALL display the final score only after the last scored trial is complete, and SHALL NOT show scores or accuracy during play.

#### Scenario: End-of-game score display
- **WHEN** a participant completes Round 2
- **THEN** the final score screen shows the total accuracy-based score for the first time

### Requirement: Alias-based leaderboard
The system SHALL display a public leaderboard under participant-chosen alias names only, with no personal identifiers on the board.

#### Scenario: Leaderboard entry
- **WHEN** a participant finishes with an alias
- **THEN** the leaderboard shows the alias and score without any demographic or contact information

### Requirement: Prize contact isolation
Any contact details collected for prize distribution SHALL be stored in a separate store that cannot be joined to session or trial research data.

#### Scenario: Contact stored separately
- **WHEN** a participant provides contact details for the prize draw
- **THEN** those details reside outside the research database with no shared key to the session record

#### Scenario: Research export excludes contact data
- **WHEN** research data is exported
- **THEN** no prize contact information can be included, even deliberately

### Requirement: Duplicate play handling
The system SHALL store enough per-session information to detect and filter repeated playthroughs from the same device during analysis, without blocking anyone from playing.

#### Scenario: Repeat play recorded but allowed
- **WHEN** the same device completes a second playthrough
- **THEN** both sessions are stored and the analysis dataset can flag them as potential repeats
