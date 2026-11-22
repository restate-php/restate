//! [`AwaitTree`]: what one await waits for, as a tree of combinators over
//! handles. It mirrors shared-core's `UnresolvedFuture` without `Unknown`, so
//! the glue crates never name the shared-core type.

use crate::error::{Error, Result};
use crate::types::Handle;
use restate_sdk_shared_core::{NotificationHandle, UnresolvedFuture};

/// One handle, or a combinator over child trees.
///
/// Awaiting a tree ([`crate::Vm::do_await_tree`]) completes as soon as the
/// tree **or any combinator nested in it** resolves, and reports which of the
/// tree's handles are then completed (as [`crate::Vm::is_completed`] would);
/// the caller decides itself whether its own result is settled. The tree is also the await point the VM
/// reports to Restate when it suspends: on protocol v7 Restate resumes the
/// invocation only once the tree can make progress, on v5/v6 when any handle
/// in it completes.
///
/// The VM moves arrived results into view one at a time, in the order they
/// arrived, and stops as soon as the tree resolves; results that arrived
/// after the deciding one are not completed yet.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum AwaitTree {
    /// This handle completes, successfully or not.
    Single(Handle),
    /// The first child completes, successfully or not (JS `Promise.race`).
    FirstCompleted(Vec<AwaitTree>),
    /// Every child completes, successfully or not (JS `Promise.allSettled`).
    AllCompleted(Vec<AwaitTree>),
    /// The first child succeeds, or every child fails (JS `Promise.any`).
    FirstSucceededOrAllFailed(Vec<AwaitTree>),
    /// Every child succeeds, or the first one fails (JS `Promise.all`).
    AllSucceededOrFirstFailed(Vec<AwaitTree>),
}

impl AwaitTree {
    /// The most combinators a tree may nest. The tree is walked recursively,
    /// here and in shared-core; the SDK itself nests one.
    pub const MAX_DEPTH: usize = 32;

    /// The combinator with this name (as the PHP side names it:
    /// `first_completed`, `all_completed`, `first_succeeded_or_all_failed`,
    /// `all_succeeded_or_first_failed`) over `children`.
    pub fn combinator(name: &str, children: Vec<AwaitTree>) -> Result<Self> {
        Ok(match name {
            "first_completed" => AwaitTree::FirstCompleted(children),
            "all_completed" => AwaitTree::AllCompleted(children),
            "first_succeeded_or_all_failed" => AwaitTree::FirstSucceededOrAllFailed(children),
            "all_succeeded_or_first_failed" => AwaitTree::AllSucceededOrFirstFailed(children),
            name => {
                return Err(Error::invalid_argument(format!(
                    "Unknown await tree combinator '{name}'."
                )))
            }
        })
    }

    /// The combinator's name, or `None` for a single handle.
    pub fn name(&self) -> Option<&'static str> {
        match self {
            AwaitTree::Single(_) => None,
            AwaitTree::FirstCompleted(_) => Some("first_completed"),
            AwaitTree::AllCompleted(_) => Some("all_completed"),
            AwaitTree::FirstSucceededOrAllFailed(_) => Some("first_succeeded_or_all_failed"),
            AwaitTree::AllSucceededOrFirstFailed(_) => Some("all_succeeded_or_first_failed"),
        }
    }

    fn children(&self) -> &[AwaitTree] {
        match self {
            AwaitTree::Single(_) => &[],
            AwaitTree::FirstCompleted(children)
            | AwaitTree::AllCompleted(children)
            | AwaitTree::FirstSucceededOrAllFailed(children)
            | AwaitTree::AllSucceededOrFirstFailed(children) => children,
        }
    }

    /// The tree's handles, depth first, each as often as it occurs.
    pub fn handles(&self) -> Vec<Handle> {
        fn collect(tree: &AwaitTree, handles: &mut Vec<Handle>) {
            match tree {
                AwaitTree::Single(handle) => handles.push(*handle),
                tree => tree
                    .children()
                    .iter()
                    .for_each(|child| collect(child, handles)),
            }
        }

        let mut handles = Vec::new();
        collect(self, &mut handles);
        handles
    }

    /// Checks that every combinator has a child and the tree nests at most
    /// [`AwaitTree::MAX_DEPTH`] combinators. shared-core would wait forever on
    /// a `FirstCompleted` without children, and resolve the other empty
    /// combinators at once; neither is something the SDK means to ask for.
    pub(crate) fn validate(&self) -> Result<()> {
        self.validate_at(0)
    }

    fn validate_at(&self, depth: usize) -> Result<()> {
        let Some(name) = self.name() else {
            return Ok(());
        };
        if depth == Self::MAX_DEPTH {
            return Err(Error::invalid_argument(format!(
                "An await tree may nest at most {} combinators.",
                Self::MAX_DEPTH
            )));
        }
        if self.children().is_empty() {
            return Err(Error::invalid_argument(format!(
                "The await tree combinator '{name}' needs at least one child."
            )));
        }
        self.children()
            .iter()
            .try_for_each(|child| child.validate_at(depth + 1))
    }
}

impl From<AwaitTree> for UnresolvedFuture {
    fn from(tree: AwaitTree) -> Self {
        fn all(children: Vec<AwaitTree>) -> Vec<UnresolvedFuture> {
            children.into_iter().map(UnresolvedFuture::from).collect()
        }

        match tree {
            AwaitTree::Single(handle) => UnresolvedFuture::Single(NotificationHandle::from(handle)),
            AwaitTree::FirstCompleted(children) => UnresolvedFuture::FirstCompleted(all(children)),
            AwaitTree::AllCompleted(children) => UnresolvedFuture::AllCompleted(all(children)),
            AwaitTree::FirstSucceededOrAllFailed(children) => {
                UnresolvedFuture::FirstSucceededOrAllFailed(all(children))
            }
            AwaitTree::AllSucceededOrFirstFailed(children) => {
                UnresolvedFuture::AllSucceededOrFirstFailed(all(children))
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::ErrorCategory;

    const NAMES: [&str; 4] = [
        "first_completed",
        "all_completed",
        "first_succeeded_or_all_failed",
        "all_succeeded_or_first_failed",
    ];

    #[test]
    fn combinators_are_named_as_the_php_side_names_them() {
        for name in NAMES {
            let tree = AwaitTree::combinator(name, vec![AwaitTree::Single(1)]).unwrap();

            assert_eq!(tree.name(), Some(name));
        }
        assert_eq!(AwaitTree::Single(1).name(), None);
    }

    #[test]
    fn an_unknown_combinator_name_is_an_invalid_argument() {
        let error = AwaitTree::combinator("any", vec![]).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), "Unknown await tree combinator 'any'.");
    }

    #[test]
    fn a_single_handle_and_combinators_with_children_are_valid() {
        assert!(AwaitTree::Single(0).validate().is_ok());
        let nested = AwaitTree::AllCompleted(vec![
            AwaitTree::Single(1),
            AwaitTree::FirstCompleted(vec![AwaitTree::Single(2)]),
        ]);
        assert!(nested.validate().is_ok());
    }

    #[test]
    fn the_depth_limit_counts_combinators() {
        let mut tree = AwaitTree::Single(1);
        for _ in 0..AwaitTree::MAX_DEPTH {
            tree = AwaitTree::FirstCompleted(vec![tree]);
        }
        assert!(tree.validate().is_ok());

        let too_deep = AwaitTree::FirstCompleted(vec![tree]);
        assert_eq!(
            too_deep.validate().unwrap_err().category(),
            ErrorCategory::InvalidArgument
        );
    }

    #[test]
    fn handles_are_listed_depth_first_with_duplicates() {
        let tree = AwaitTree::AllCompleted(vec![
            AwaitTree::Single(3),
            AwaitTree::FirstCompleted(vec![AwaitTree::Single(1), AwaitTree::Single(3)]),
            AwaitTree::Single(2),
        ]);

        assert_eq!(tree.handles(), vec![3, 1, 3, 2]);
        assert_eq!(AwaitTree::Single(7).handles(), vec![7]);
    }

    #[test]
    fn the_tree_becomes_the_matching_shared_core_future() {
        let tree = AwaitTree::AllCompleted(vec![
            AwaitTree::Single(1),
            AwaitTree::FirstSucceededOrAllFailed(vec![AwaitTree::Single(2)]),
        ]);

        assert_eq!(
            format!("{:?}", UnresolvedFuture::from(tree)),
            "all_completed(1, first_succeeded_or_all_failed(2))"
        );
    }
}
