//! Awaiting a combinator tree ([`AwaitTree`]): when each combinator resolves,
//! which handles PHP then sees completed, and the await point the VM reports
//! to Restate when it suspends (a flat set of notifications on v5/v6, the
//! tree itself on v7).

use crate::protocol::{
    call_completion_notification_message, CallCommandMessage, CallCompletionNotificationMessage,
    CombinatorType, Future, Message, SuspensionMessage, SuspensionMessageV6,
};
use crate::support::*;
use restate_php_core::{
    AwaitTree, Bytes, CallTarget, ErrorCategory, Handle, NotificationResult, Progress, TargetKind,
    Vm,
};

/// The four combinators, by the name PHP uses, with the wire type each one
/// suspends with on v7.
const COMBINATORS: [(&str, CombinatorType); 4] = [
    ("first_completed", CombinatorType::FirstCompleted),
    ("all_completed", CombinatorType::AllCompleted),
    (
        "first_succeeded_or_all_failed",
        CombinatorType::FirstSucceededOrAllFailed,
    ),
    (
        "all_succeeded_or_first_failed",
        CombinatorType::AllSucceededOrFirstFailed,
    ),
];

fn greeter() -> CallTarget {
    CallTarget {
        kind: TargetKind::Service,
        service: "Greeter".to_string(),
        handler: "greet".to_string(),
        key: None,
        headers: vec![],
        scope: None,
        limit_key: None,
    }
}

/// A running VM on `content_type` that made three calls; the handles and the
/// completion ids their results arrive with.
struct ThreeCalls {
    vm: Vm,
    handles: [Handle; 3],
    completions: [u32; 3],
}

impl ThreeCalls {
    fn new(invocation: Invocation) -> Self {
        let mut vm = invocation.running();
        let handles = [0, 1, 2].map(|_| vm.sys_call(greeter(), Bytes::new(), None).unwrap());
        let commands: Vec<CallCommandMessage> = output(&mut vm)
            .iter()
            .filter(|frame| frame.message_type == CallCommandMessage::TYPE)
            .map(|frame| frame.decode())
            .collect();
        let completions = [0, 1, 2].map(|i| commands[i].result_completion_id);
        Self {
            vm,
            handles,
            completions,
        }
    }

    /// With the request body open, so results can arrive while it runs.
    fn open(content_type: &'static str) -> Self {
        Self::new(Invocation::new().content_type(content_type).open())
    }

    /// With the request body closed: awaiting anything not completed
    /// suspends.
    fn closed(content_type: &'static str) -> Self {
        Self::new(Invocation::new().content_type(content_type))
    }

    fn tree(&self, combinator: &str) -> AwaitTree {
        AwaitTree::combinator(
            combinator,
            self.handles
                .iter()
                .copied()
                .map(AwaitTree::Single)
                .collect(),
        )
        .unwrap()
    }

    fn succeed(&mut self, call: usize) {
        self.deliver(
            call,
            call_completion_notification_message::Result::Value(value(b"\"ok\"")),
        );
    }

    fn fail(&mut self, call: usize) {
        self.deliver(
            call,
            call_completion_notification_message::Result::Failure(failure(409, "denied", &[])),
        );
    }

    fn deliver(&mut self, call: usize, result: call_completion_notification_message::Result) {
        self.vm
            .notify_input(encode(&CallCompletionNotificationMessage {
                completion_id: self.completions[call],
                result: Some(result),
            }));
    }

    /// Awaits `combinator` over the three calls; once it completes, the
    /// handles it reports are exactly those `is_completed` reports, in call
    /// order.
    fn await_tree(&mut self, combinator: &str) -> Progress<()> {
        let tree = self.tree(combinator);
        let progress = self.vm.do_await_tree(tree).unwrap();
        if let Progress::Completed(handles) = &progress {
            let completed: Vec<Handle> = self
                .handles
                .into_iter()
                .filter(|&handle| self.vm.is_completed(handle))
                .collect();
            assert_eq!(handles, &completed);
        }
        progress.map(|_| ())
    }

    /// Which of the three calls the VM reports completed, in call order.
    fn completed(&self) -> [bool; 3] {
        self.handles.map(|handle| self.vm.is_completed(handle))
    }
}

/// Delivers results one at a time in `steps` (call index and whether it
/// succeeds) and returns, after each, whether awaiting `combinator` over the
/// three calls resolved.
fn resolution(content_type: &'static str, combinator: &str, steps: &[(usize, bool)]) -> Vec<bool> {
    let mut calls = ThreeCalls::open(content_type);
    assert_eq!(calls.await_tree(combinator), Progress::ReadFromInput);
    steps
        .iter()
        .map(|&(call, succeeds)| {
            if succeeds {
                calls.succeed(call);
            } else {
                calls.fail(call);
            }
            match calls.await_tree(combinator) {
                Progress::Completed(()) => true,
                Progress::ReadFromInput => false,
                other => panic!("{content_type} {combinator}: unexpected {other:?}"),
            }
        })
        .collect()
}

#[test]
fn first_completed_resolves_on_the_first_result_even_a_failure() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(content_type, "first_completed", &[(1, false)]),
            [true],
            "{content_type}"
        );
        assert_eq!(
            resolution(content_type, "first_completed", &[(2, true)]),
            [true],
            "{content_type}"
        );
    }
}

#[test]
fn all_completed_waits_for_every_result_whatever_it_is() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(
                content_type,
                "all_completed",
                &[(1, false), (0, true), (2, false)]
            ),
            [false, false, true],
            "{content_type}"
        );
    }
}

#[test]
fn first_succeeded_or_all_failed_waits_past_failures_for_a_success() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(
                content_type,
                "first_succeeded_or_all_failed",
                &[(2, false), (0, false), (1, true)]
            ),
            [false, false, true],
            "{content_type}"
        );
    }
}

#[test]
fn first_succeeded_or_all_failed_resolves_once_every_child_failed() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(
                content_type,
                "first_succeeded_or_all_failed",
                &[(0, false), (2, false), (1, false)]
            ),
            [false, false, true],
            "{content_type}"
        );
    }
}

#[test]
fn all_succeeded_or_first_failed_waits_for_every_success() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(
                content_type,
                "all_succeeded_or_first_failed",
                &[(1, true), (2, true), (0, true)]
            ),
            [false, false, true],
            "{content_type}"
        );
    }
}

/// A tree that waited for every child even after one failed would leave an
/// invocation waiting for results that may never come.
#[test]
fn all_succeeded_or_first_failed_resolves_on_the_first_failure_with_children_pending() {
    for content_type in [V5, V6, V7] {
        assert_eq!(
            resolution(
                content_type,
                "all_succeeded_or_first_failed",
                &[(0, true), (2, false)]
            ),
            [false, true],
            "{content_type}"
        );
    }
}

#[test]
fn after_resolving_the_completed_handles_are_the_ones_that_arrived() {
    let mut calls = ThreeCalls::open(V7);
    calls.succeed(2);
    calls.fail(0);

    assert_eq!(
        calls.await_tree("all_succeeded_or_first_failed"),
        Progress::Completed(())
    );
    assert_eq!(calls.completed(), [true, false, true]);

    let [first, _, third] = calls.handles;
    assert!(matches!(
        calls.vm.take_notification_result(first).unwrap(),
        NotificationResult::Failure(_)
    ));
    assert_eq!(
        calls.vm.take_notification_result(third).unwrap(),
        NotificationResult::Success(Bytes::from_static(b"\"ok\""))
    );
    assert_eq!(
        calls.completed(),
        [false, false, false],
        "a taken handle is no longer completed"
    );
}

/// The VM moves results into view one at a time and stops as soon as the
/// tree resolves, so results that arrived after the deciding one are not
/// completed yet.
#[test]
fn resolving_stops_at_the_result_that_decides_the_tree() {
    let mut calls = ThreeCalls::open(V7);
    calls.fail(1);
    calls.succeed(0);

    assert_eq!(
        calls.await_tree("all_succeeded_or_first_failed"),
        Progress::Completed(())
    );
    assert_eq!(calls.completed(), [false, true, false]);
}

#[test]
fn a_nested_combinator_that_resolves_completes_the_await_before_the_root_does() {
    let mut calls = ThreeCalls::open(V7);
    let [first, second, third] = calls.handles;
    let tree = AwaitTree::combinator(
        "all_completed",
        vec![
            AwaitTree::Single(first),
            AwaitTree::combinator(
                "first_completed",
                vec![AwaitTree::Single(second), AwaitTree::Single(third)],
            )
            .unwrap(),
        ],
    )
    .unwrap();
    calls.succeed(2);

    // shared-core reports progress as soon as any combinator in the tree
    // resolves; the caller looks at which handles completed.
    assert_eq!(
        calls.vm.do_await_tree(tree).unwrap(),
        Progress::Completed(vec![third])
    );
    assert_eq!(calls.completed(), [false, false, true]);
}

#[test]
fn a_tree_suspends_on_v5_and_v6_waiting_for_every_handle_in_it() {
    for content_type in [V5, V6] {
        for (combinator, _) in COMBINATORS {
            let mut calls = ThreeCalls::closed(content_type);

            assert_eq!(calls.await_tree(combinator), Progress::Suspended);

            let suspension: SuspensionMessageV6 = single(&output(&mut calls.vm));
            let mut waiting = suspension.waiting_completions.clone();
            waiting.sort_unstable();
            assert_eq!(
                waiting,
                calls.completions.to_vec(),
                "{content_type} {combinator}"
            );
            assert_eq!(suspension.waiting_signals, vec![CANCEL_SIGNAL_ID]);
        }
    }
}

/// The tree a v7 suspension carries for a combinator over handles: the
/// combinator nested under the cancel signal, which shared-core always awaits
/// too.
fn nested_under_the_cancel_signal(awaiting_on: Option<Future>) -> Future {
    let root = awaiting_on.expect("the message carries the await point");
    assert_eq!(root.combinator_type, CombinatorType::FirstCompleted as i32);
    assert_eq!(root.waiting_signals, vec![CANCEL_SIGNAL_ID]);
    assert!(root.waiting_completions.is_empty());
    assert_eq!(root.nested_futures.len(), 1);
    root.nested_futures.into_iter().next().unwrap()
}

#[test]
fn a_tree_suspends_on_v7_with_the_tree_as_the_await_point() {
    for (combinator, wire_type) in COMBINATORS {
        let mut calls = ThreeCalls::closed(V7);

        assert_eq!(calls.await_tree(combinator), Progress::Suspended);

        let suspension: SuspensionMessage = single(&output(&mut calls.vm));
        let tree = nested_under_the_cancel_signal(suspension.awaiting_on);
        assert_eq!(tree.combinator_type, wire_type as i32, "{combinator}");
        assert_eq!(tree.waiting_completions, calls.completions.to_vec());
        assert!(tree.nested_futures.is_empty());
    }
}

#[test]
fn a_nested_tree_suspends_on_v7_with_its_nested_combinators() {
    let mut calls = ThreeCalls::closed(V7);
    let [first, second, third] = calls.handles;
    let tree = AwaitTree::combinator(
        "all_succeeded_or_first_failed",
        vec![
            AwaitTree::Single(first),
            AwaitTree::combinator(
                "first_succeeded_or_all_failed",
                vec![AwaitTree::Single(second), AwaitTree::Single(third)],
            )
            .unwrap(),
        ],
    )
    .unwrap();

    assert_eq!(calls.vm.do_await_tree(tree).unwrap(), Progress::Suspended);

    let suspension: SuspensionMessage = single(&output(&mut calls.vm));
    let root = nested_under_the_cancel_signal(suspension.awaiting_on);
    assert_eq!(
        root.combinator_type,
        CombinatorType::AllSucceededOrFirstFailed as i32
    );
    assert_eq!(root.waiting_completions, vec![calls.completions[0]]);
    assert_eq!(root.nested_futures.len(), 1);
    assert_eq!(
        root.nested_futures[0].combinator_type,
        CombinatorType::FirstSucceededOrAllFailed as i32
    );
    assert_eq!(
        root.nested_futures[0].waiting_completions,
        calls.completions[1..].to_vec()
    );
}

/// Restate resumes a v7 invocation when the tree it suspended on can make
/// progress, so the tree must only list what is still pending: children that
/// already settled their part are left out.
#[test]
fn a_partly_resolved_tree_suspends_on_v7_with_only_the_pending_children() {
    for (combinator, steps) in [
        ("all_completed", [(0, false)]),
        ("first_succeeded_or_all_failed", [(0, false)]),
        ("all_succeeded_or_first_failed", [(0, true)]),
    ] {
        let mut calls = ThreeCalls::open(V7);
        assert_eq!(calls.await_tree(combinator), Progress::ReadFromInput);
        for (call, succeeds) in steps {
            if succeeds {
                calls.succeed(call);
            } else {
                calls.fail(call);
            }
        }
        output(&mut calls.vm);
        calls.vm.notify_input_closed();

        assert_eq!(calls.await_tree(combinator), Progress::Suspended);

        let suspension: SuspensionMessage = single(&output(&mut calls.vm));
        let tree = nested_under_the_cancel_signal(suspension.awaiting_on);
        let mut waiting = tree.waiting_completions.clone();
        waiting.sort_unstable();
        assert_eq!(waiting, calls.completions[1..].to_vec(), "{combinator}");
    }
}

#[test]
fn a_combinator_without_children_is_an_invalid_argument() {
    for (combinator, _) in COMBINATORS {
        let mut calls = ThreeCalls::open(V7);
        let tree = AwaitTree::combinator(combinator, vec![]).unwrap();

        let error = calls.vm.do_await_tree(tree).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(
            error.message(),
            format!("The await tree combinator '{combinator}' needs at least one child.")
        );
    }
}

#[test]
fn a_nested_combinator_without_children_is_an_invalid_argument() {
    let mut calls = ThreeCalls::open(V7);
    let tree = AwaitTree::AllCompleted(vec![
        AwaitTree::Single(calls.handles[0]),
        AwaitTree::FirstCompleted(vec![]),
    ]);

    let error = calls.vm.do_await_tree(tree).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::InvalidArgument);
    assert_eq!(
        error.message(),
        "The await tree combinator 'first_completed' needs at least one child."
    );
}

#[test]
fn a_tree_deeper_than_the_limit_is_an_invalid_argument() {
    let mut calls = ThreeCalls::open(V7);
    let mut tree = AwaitTree::Single(calls.handles[0]);
    for _ in 0..=AwaitTree::MAX_DEPTH {
        tree = AwaitTree::FirstCompleted(vec![tree]);
    }

    let error = calls.vm.do_await_tree(tree).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::InvalidArgument);
    assert_eq!(
        error.message(),
        format!(
            "An await tree may nest at most {} combinators.",
            AwaitTree::MAX_DEPTH
        )
    );
}

#[test]
fn an_unknown_combinator_is_an_invalid_argument() {
    let error = AwaitTree::combinator("unknown", vec![AwaitTree::Single(1)]).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::InvalidArgument);
    assert_eq!(error.message(), "Unknown await tree combinator 'unknown'.");
}
