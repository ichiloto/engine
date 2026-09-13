# Shared accepted tile fixtures

These seven JSON files are byte-identical copies of the GPUI renderer's
`fixtures/tile-batches` accepted-frame and hello fixtures. Do not independently
change them: update the shared contract and both repositories together.

PHP is the outbound producer, not a second wire receiver. Engine tests check
typed tile values, exact serialization, bounds, capability negotiation,
transactional retries and aggregate limits. Rust owns strict wire parsing and
the complete malformed/session/image/atomic-rejection fixture corpus. Empty and
omitted collections are equivalent full-state clearing; Engine emits omission.
The images named in these values are not decoded by Engine tests.
