"""Shared release-plan schema policy for CLI recovery and publication."""

from __future__ import annotations

import hashlib
import json
from typing import Any

CURRENT_PLAN_SCHEMA = "durable-workflow.release-plan/v2"
HISTORICAL_PLAN_SCHEMA = "durable-workflow.release-plan/v1"
HISTORICAL_PLAN_DIGESTS = frozenset(
    {
        "0be354d5ea603170b6aef8ae0d9861886c4ccc0f75e6acb763239b30dd5d8ba3",
        "295a3f654716ea8cd8dc693c1cd15a4b487737e5f01184bad7363fbde6717c40",
        "486d9ef7c5a7f4443a89566cab33d7f2bccc518254ab6698d918a431d6a1c9ce",
        "498804a2c7fd5b0e34f93ef080bea3073bc98e420e8bf84a98ca4cdb94729973",
        "7bd737c92f139eec33026bc88a6491dc635d819a87a61c985e14e06aca645582",
        "80e88698fa37b6d738d111dd2be3e3c145607973f8147c54cc25e5d91d415b17",
        "9c0a5879652a2d5f4806a9167399687328c1764fa10dbc8d76215b43ac83b9d6",
        "db90616c98f305c61d7eb2fb9ed03cc28f06963e9ca020c8ef6d7c6a8557f7bc",
        "e1fc6e20c9d2ded0b5e7ac4d6be75ba861d31fc4b2db651dc0272dca623f2c7f",
    }
)


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n").encode()


def release_plan_digest(plan: Any) -> str:
    return hashlib.sha256(canonical_json(plan)).hexdigest()


def supports_release_plan(plan: Any) -> bool:
    if not isinstance(plan, dict):
        return False
    if plan.get("schema") == CURRENT_PLAN_SCHEMA:
        return True
    return (
        plan.get("schema") == HISTORICAL_PLAN_SCHEMA
        and release_plan_digest(plan) in HISTORICAL_PLAN_DIGESTS
    )
