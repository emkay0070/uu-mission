# UU Mission — Mission Translator
## Product Vision & Architecture Decision Record

**Status:** Agreed product direction; implementation and technology choices remain subject to validation.

### 1. Product Vision

UU Mission must provide missionaries with a natural, fast, and seamless face-to-face voice translation experience, comparable in conversational flow to ChatGPT Voice.

The objective is to help a missionary communicate with someone who speaks another language without requiring either person to type, repeatedly press recording controls, or manage technical processing steps.

The missionary should focus on the person they're speaking to—not the technology.

**Core principle: The missionary should experience a conversation, not a translation pipeline.**

### 2. Target User Experience

A missionary opens Mission Translator, selects the two languages, and taps Start Conversation.

The system then supports a continuous, hands-free, turn-taking conversation:

1. The missionary speaks in their language.
2. UU Mission recognises the speech.
3. The system translates the intended meaning into the other language.
4. The translated speech plays automatically.
5. The other person responds in their language.
6. UU Mission translates and speaks the response back.
7. The conversation continues without requiring the user to restart recording for every sentence.

The interface must support natural pauses, interruptions, replay, correction, recovery from errors, and clear visual feedback.

A manual fallback must remain available when automatic speech detection fails.

The initial acceptance target is a complete English–Luganda conversation in both directions. Additional languages should be tested individually before being declared supported.

### 3. Conversational Quality Requirements

The system must prioritise:

- Low end-to-end latency.
- Reliable speech recognition.
- Meaning-preserving translation.
- Understandable, natural-sounding speech.
- Automatic turn-taking.
- Appropriate interruption handling.
- Correct handling of names and local expressions.
- Clear recovery from network or model failures.
- Mobile-first usability in real field conditions.

Do not claim a real-time or seamless experience based solely on successful API responses.

Measure actual end-to-end latency and test with real speakers, accents, and realistic background noise.

### 4. Current Architecture Problem

The existing integration relies on sequential speech-processing stages:

Recording → Speech-to-text → Translation → Text-to-speech → Audio playback.

Each external request can introduce additional latency, failures, and timeouts.

Repeatedly increasing request timeouts is not an acceptable long-term performance strategy.

The team must investigate stage-level latency and determine whether the bottleneck is speech recognition, translation, speech generation, network communication, or the sequential architecture.

Preserve working functionality while investigating. Do not repeatedly rewrite the translator or perform unrelated audits.

### 5. Agreed Technical Direction

Move toward a low-latency conversational architecture that supports continuous audio interaction, automatic turn-taking, and interruption handling.

Evaluate suitable real-time speech-processing approaches while preserving the requirement for strong African-language support.

Do not select a technology solely because it performs well in English.

The architecture should separate the following responsibilities:

- Speech recognition.
- Translation.
- Speech generation.
- Mission-specific language knowledge.
- Conversation state and turn-taking.
- Audio playback and interruption handling.

These components should be replaceable independently wherever practical.

### 6. Mission Language Library

UU Mission must own its mission-specific language knowledge in its own database.

The proposed language library should support:

- Words and phrases.
- Approved translations.
- Alternative spellings.
- Local names and place names.
- Pronunciation guidance.
- Context and usage notes.
- Language and dialect information where available.
- Review and approval status.
- Optional recorded audio.
- Optional speech-generation pronunciation hints.
- Correction history and contributor attribution.

The library should help improve recognition, translation consistency, and speech output where the selected models and architecture support those capabilities.

Dictionary matching must be context-aware. Do not blindly replace similar words or apply a phrase-level translation to an unrelated sentence.

Keep correct written text separate from any alternative pronunciation representation used for speech generation.

Unverified corrections must not automatically become authoritative translations for all users.

Provide an appropriate review process for Scripture terminology, doctrinal language, and other sensitive mission vocabulary.

### 7. Mission Phrasebook

Develop a phrasebook of common missionary expressions, including:

- Greetings and introductions.
- Explaining the purpose of a visit.
- Asking permission to speak.
- Offering prayer.
- Inviting someone to a Bible study.
- Discussing community needs.
- Arranging follow-up visits.

Where practical, use recordings from fluent speakers for approved phrases.

The phrasebook should provide reliable playback without requiring a new TTS request for phrases with available recordings.

Support downloadable phrase packs for offline use.

Phrasebook playback is a complement to general translation, not a replacement for it.

### 8. Independence from External Providers

The long-term objective is to reduce dependence on external speech and translation APIs while increasing control over language quality and system behaviour.

Evaluate a hybrid approach consisting of:

- UU Mission's own language dictionary and corrections.
- Approved translations and recorded phrases.
- Suitable open-source speech-recognition models.
- Suitable translation models.
- Suitable open-source speech-generation models.
- Hosted services where they provide a demonstrably better result.

Investigate licensing, deployment requirements, hardware requirements, language quality, latency, privacy, and maintenance costs before adopting any model.

Do not assume that open-source models can run efficiently on the current application server.

Do not attempt to train complete speech models immediately. Prioritise working conversation quality, then replace external dependencies when a validated alternative is available.

### 9. Privacy and Field Reliability

- Keep provider credentials on the backend.
- Do not expose API tokens in the frontend.
- Avoid logging raw audio or sensitive conversation text.
- Minimise unnecessary retention of recordings and transcripts.
- Disclose external processing where applicable.
- Handle unreliable connectivity gracefully.
- Provide useful error messages without exposing internal implementation details.
- Support offline phrasebooks and recorded phrases as an initial offline capability.

Broader offline speech recognition, translation, and speech generation remain future capabilities requiring separate feasibility testing.

### 10. Implementation Strategy

Work in focused stages.

**Stage 1 — Establish a reliable baseline**
- Verify the existing English–Luganda speech pipeline.
- Measure latency for each processing stage.
- Identify the real causes of slow requests and timeouts.
- Confirm that translated speech actually plays in the browser.

**Stage 2 — Improve the conversation experience**
- Implement continuous turn-taking.
- Automate recording submission and translated playback.
- Add listening indicators, pause, replay, correction, and interruption controls.
- Prevent the system from transcribing its own playback.
- Preserve transcripts when later processing stages fail.

**Stage 3 — Build the Mission Language Library**
- Create the database-backed vocabulary and correction system.
- Add approved translations and pronunciation guidance.
- Provide a review process for language contributors.
- Integrate language knowledge into supported processing stages.

**Stage 4 — Build the Mission Phrasebook**
- Add approved common missionary expressions.
- Support recordings by fluent speakers.
- Provide reliable playback and downloadable language packs.

**Stage 5 — Evaluate greater technical independence**
- Benchmark open-source STT, translation, and TTS models.
- Compare their performance against the existing services.
- Evaluate suitable hosting and hardware options.
- Replace individual external dependencies only when testing demonstrates a clear benefit.

### 11. Acceptance Criteria

The initial conversational milestone is complete only when real browser testing demonstrates that:

1. A missionary can start a conversation once.
2. English speech can be translated into understandable spoken Luganda.
3. A Luganda response can be translated into understandable spoken English.
4. The conversation continues without repeated manual recording submissions.
5. Audio playback, turn-taking, and microphone state behave reliably.
6. Users can replay a result and recover from failed processing.
7. The measured delays are recorded and acceptable for the intended field experience.
8. Language quality has been checked by competent speakers.

Do not mark unsupported languages, streaming behaviour, offline capability, or model independence as complete without evidence.

### 12. Scope Discipline

This document records the agreed product vision and direction. It does not authorise an immediate rewrite of the entire translator.

Preserve existing working code, keep changes focused on demonstrated needs, and validate each stage before expanding scope.

Do not introduce additional infrastructure or paid providers without a clear technical reason.

The objective is not to build the most complicated speech architecture. It is to deliver the most useful, natural, and dependable voice conversation experience for missionaries.

**Final product principle: Build a translator that helps two people communicate naturally, not a system that makes them wait for technology.**
