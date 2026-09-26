<?php
// CASE D 8.3 get_class() without arguments deprecated
class Get_Class_User {
	public function name() { return get_class(); }
}

// CASE D 8.3 get_parent_class() without arguments deprecated
class Get_Parent_User extends Get_Class_User {
	public function parent_name() { return get_parent_class(); }
}

// CASE D 8.3 assert_options() deprecated
assert_options( ASSERT_ACTIVE, 1 );

// CASE D 8.3 MT_RAND_PHP constant deprecated
mt_srand( 42, MT_RAND_PHP );

// CASE D 8.3 ldap_connect() with two arguments deprecated
$l = ldap_connect( 'host', 389 );

// CASE OK 8.3 control
class Typed_Const { const string NAME = 'x'; }
