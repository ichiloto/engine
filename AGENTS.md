# Engine Engineering Rules

These rules intentionally repeat the repository-level rules so engine work cannot drift into local-only shortcuts.

## Correctness First

Always favor correctness, maintainability, and complete design over speed. No shortcuts. No hacky solutions. If a problem deserves a proper model, architecture, state machine, picker, abstraction, or validation layer, build that instead of patching symptoms.

## Solve Globally By Default

When fixing a problem, solve the root issue globally rather than making a narrow local workaround, unless Andrew explicitly asks for a local-only change. Look for the broader pattern, existing architecture, and future maintenance cost before implementing.

## Best Practices Are Required

Apply best practices by default: explicit data models, stable references, validation at boundaries, clear ownership, cohesive APIs, tests for behavior, and clean separation of concerns. Avoid hidden conventions, magic strings, fragile coupling, and UI flows that rely on memory or perfect spelling.

## Editor References Must Be Selected

When editor fields reference another resource, they must use a selector, picker, dialog, browser, or constrained list. Do not require manual typing for references to actors, classes, items, skills, animations, summon cutscenes, maps, events, files, or other resources known to the editor. Manual text is for authored free text, not resource references.
